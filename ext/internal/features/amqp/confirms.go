package amqp_feature

import (
	"context"
	"errors"
	"time"

	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/tasks"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

const (
	// confirmQueueSize buffers the publisher confirms the broker has sent but a wait loop
	// has not collected yet.
	confirmQueueSize = 1024
	// returnQueueSize buffers the messages the broker returned as unroutable.
	returnQueueSize = 128
)

// errWaitTimeout is what a wait loop reports when its deadline passes. It reaches PHP
// unwrapped (see classify), where it becomes the exception the caller asked for —
// PublishConfirmTimeoutException — so nothing matches on the wording and it can simply read
// as English.
var errWaitTimeout = errors.New("wait timeout exceeded")

// handleConfirmSelect puts the channel into publisher-confirm mode and starts collecting
// what the broker reports about the messages published on it.
func (f *AmqpFeature) handleConfirmSelect(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ConfirmSelectParams

	if !decodeParams(task, raw, &params, "confirm select params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs, defaultRpcTimeout)
	defer cancel()

	if err := entry.startConfirmMode(ctx, params.NoWait); err != nil {
		fail(task, entry, "confirm select", err)

		return
	}

	respondDone(task, startTime)
}

// handleConfirmWait waits until every message published on the channel since the last
// wait has been confirmed or rejected, and hands back what arrived, the returned messages
// included.
func (f *AmqpFeature) handleConfirmWait(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ChannelParams

	if !decodeParams(task, raw, &params, "confirm wait params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	// A zero timeout means "wait until the broker answers", so the wait rides the flow
	// context alone — a stopped coroutine still ends it.
	result, err := entry.waitForConfirms(
		task.GetContext(),
		time.Duration(max(params.TimeoutMs, 0))*time.Millisecond,
	)

	if err != nil {
		fail(task, entry, "confirm wait", err)

		return
	}

	respond(task, result, startTime)
}

// startConfirmMode puts the channel into publisher-confirm mode and starts collecting what
// the broker reports. Calling it twice is a no-op: NotifyPublish appends listeners, so a
// second registration would count every confirmation twice — and the driver then fans every
// confirmation out to both, which once the buffer of the one nobody reads is full costs the
// whole connection's reader a notify timeout per message.
//
// The check that makes that impossible is the one inside the closure. It runs while do()
// holds channelMutex, so two callers on the same channel are serialized there, and the
// second sees the flag the first set. A claim taken before the driver call was tried
// instead, and could not be given back honestly: a call whose deadline passed while the
// broker was already answering leaves the channel in confirm mode for good.
func (e *channelEntry) startConfirmMode(ctx context.Context, noWait bool) error {
	if e.inConfirmMode() {
		return nil
	}

	return e.do(ctx, func(channel *amqp091.Channel) error {
		if e.inConfirmMode() {
			return nil
		}

		if confirmError := channel.Confirm(noWait); confirmError != nil {
			return confirmError
		}

		confirms := channel.NotifyPublish(make(chan amqp091.Confirmation, confirmQueueSize))

		// Set while this goroutine still holds the channel: a publish waiting for the
		// same lock is counted, and one that already went through was published before
		// confirm mode and gets no confirmation.
		e.mutex.Lock()
		e.confirming = true
		e.mutex.Unlock()

		// Handed over here, beside the flag it belongs to, and not after do() returns:
		// do() stops waiting when the command deadline passes or the flow stops, while
		// the collector runs on. A channel left in confirm mode with nobody draining the
		// listener would strand every later confirm wait on it (pending would never fall
		// back to zero).
		e.confirmsReady <- confirms

		return nil
	})
}

func (e *channelEntry) inConfirmMode() bool {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	return e.confirming
}

// publishing records one more message awaiting a confirm; it is a no-op outside
// publisher-confirm mode.
func (e *channelEntry) publishing() {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	e.lastUsedAt = time.Now()

	if !e.confirming {
		return
	}

	e.pending++
}

// publishFailed takes back what publishing() counted: a publish the broker never received
// gets no confirmation, and a wait loop counting on one would never end.
func (e *channelEntry) publishFailed() {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	if e.pending > 0 {
		e.pending--
	}
}

// collect drains everything the driver reports about this channel in one goroutine: the
// messages the broker returned, the publisher confirms once confirm mode is on, and the
// close that ends the channel.
//
// One goroutine and not two, because the order of the first two matters. The broker sends
// basic.return before the basic.ack of the same message, and the driver enqueues them in
// that order — but two collectors racing for the entry's lock would record them in either.
// A publisher waiting for its confirm would then be told the message was stored a moment
// before the return saying it reached no queue was recorded, and would never see it.
//
// So a confirmation is only recorded once every return already queued has been: those were
// enqueued before it, and therefore belong to messages the broker settled no later.
//
// The close listener is read here as well rather than by a watcher of its own, which is
// what gives the entry the broker's reason for the close — without it a channel the broker
// took down over a 404 could only report "No channel available." to whatever ran next.
//
// The confirmation listener arrives over confirmsReady when a coroutine puts the channel
// into confirm mode. Until then it is nil, and a receive on a nil channel blocks forever —
// which is exactly the right behaviour for a listener that does not exist yet.
func (e *channelEntry) collect(returns chan amqp091.Return, closed chan *amqp091.Error) {
	var confirms chan amqp091.Confirmation

	for returns != nil || confirms != nil || closed != nil {
		select {
		case returned, ok := <-returns:
			if !ok {
				returns = nil

				continue
			}

			e.recordReturn(returned)
		case confirms = <-e.confirmsReady:
		case confirmation, ok := <-confirms:
			if !ok {
				confirms = nil

				continue
			}

			e.drainReturns(returns)

			e.recordConfirmation(confirmation)
		case reason, ok := <-closed:
			closed = nil

			if !ok {
				continue
			}

			// Recorded before the entry is dropped, so classify() finds it there:
			// close() is what sets the closed flag, and a command that sees that flag
			// therefore sees the reason too.
			e.recordCloseReason(reason)

			// A protocol error (a passive declare of a queue that does not exist, a
			// publish to a missing exchange) makes the broker close the channel. Without
			// this the dead channel would sit in the registry until the idle sweeper,
			// answering commands with a confusing error and counting towards the
			// connection's channel limit.
			//
			// On its own goroutine: closing waits for the driver, and this one still has
			// the listeners above to drain.
			go getChannels().close(e.id)
		}
	}
}

// drainReturns records the returns already queued, without waiting for more.
func (e *channelEntry) drainReturns(returns chan amqp091.Return) {
	for {
		select {
		case returned, ok := <-returns:
			if !ok {
				return
			}

			e.recordReturn(returned)
		default:
			return
		}
	}
}

func (e *channelEntry) recordCloseReason(reason *amqp091.Error) {
	e.mutex.Lock()

	e.closeReason = reason

	e.mutex.Unlock()
}

func (e *channelEntry) recordConfirmation(confirmation amqp091.Confirmation) {
	e.mutex.Lock()

	e.confirmations = appendBounded(e.confirmations, payloads.Confirmation{
		DeliveryTag: confirmation.DeliveryTag,
		Acked:       confirmation.Ack,
	}, confirmQueueSize)

	if e.pending > 0 {
		e.pending--
	}

	e.mutex.Unlock()

	e.wake()
}

func (e *channelEntry) recordReturn(returned amqp091.Return) {
	e.mutex.Lock()

	e.returns = appendBounded(e.returns, payloads.ReturnedMessage{
		ReplyCode:    int(returned.ReplyCode),
		ReplyText:    returned.ReplyText,
		ExchangeName: returned.Exchange,
		RoutingKey:   returned.RoutingKey,
		Body:         string(returned.Body),
		Properties: payloads.Properties{
			ContentType:     returned.ContentType,
			ContentEncoding: returned.ContentEncoding,
			Headers:         tableToMap(returned.Headers),
			DeliveryMode:    int(returned.DeliveryMode),
			Priority:        int(returned.Priority),
			CorrelationId:   returned.CorrelationId,
			ReplyTo:         returned.ReplyTo,
			Expiration:      returned.Expiration,
			MessageId:       returned.MessageId,
			Timestamp:       timestampToUnix(returned.Timestamp),
			Type:            returned.Type,
			UserId:          returned.UserId,
			AppId:           returned.AppId,
		},
	}, returnQueueSize)

	e.mutex.Unlock()

	e.wake()
}

// appendBounded appends and keeps the tail at most limit long, dropping from the front.
//
// What a channel keeps for a wait loop that has not come. An application may publish in
// confirm mode, or as mandatory, and never call the matching wait — a returned message
// carries its whole body, so an unbounded backlog is the channel quietly eating the heap.
// The oldest go first: a wait loop that does arrive wants what happened recently, and
// ext-amqp keeps nothing at all when no callback is registered.
func appendBounded[T any](values []T, value T, limit int) []T {
	values = append(values, value)

	if len(values) <= limit {
		return values
	}

	return values[len(values)-limit:]
}

// wake releases every wait loop parked on this channel; each re-checks its own condition
// and parks again if it is not met.
func (e *channelEntry) wake() {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	close(e.changed)

	e.changed = make(chan struct{})
}

// waitForConfirms waits until every message published since the last wait has been
// confirmed or rejected, and returns everything collected on the way, the returned messages
// included (ext-amqp's waitForConfirm catches basic.return too).
//
// A channel that was never put into confirm mode has nothing to wait for and runs into the
// deadline, which is what the extension does as well.
func (e *channelEntry) waitForConfirms(ctx context.Context, timeout time.Duration) (payloads.WaitResult, error) {
	return e.wait(ctx, timeout, func() bool {
		return e.confirming && e.pending == 0
	}, e.drainLocked)
}

// wait blocks until the condition holds (checked under the entry's lock), the deadline
// passes, the channel goes away, or the flow stops, and hands back what the drain took.
//
// The timeout is reported as the extension words it, unwrapped by classify, so an
// application that reads the message sees what it saw before.
func (e *channelEntry) wait(
	ctx context.Context,
	timeout time.Duration,
	ready func() bool,
	drain func() payloads.WaitResult,
) (payloads.WaitResult, error) {
	var deadline <-chan time.Time

	if timeout > 0 {
		timer := time.NewTimer(timeout)
		defer timer.Stop()

		deadline = timer.C
	}

	for {
		e.mutex.Lock()

		if ready() {
			result := drain()

			e.mutex.Unlock()

			return result, nil
		}

		// Taken before the lock is released: an event that fires in between closes this
		// very channel instead of being missed.
		changed := e.changed

		e.mutex.Unlock()

		select {
		case <-changed:
		case <-deadline:
			return payloads.WaitResult{}, errWaitTimeout
		case <-e.gone:
			// Reported as the plain "closed" error and classified from there: classify()
			// is the one place that knows how to tell a channel the broker took away from
			// a connection that died under it, and how to name the reason for either.
			return payloads.WaitResult{}, amqp091.ErrClosed
		case <-ctx.Done():
			return payloads.WaitResult{}, ctx.Err()
		}
	}
}

// drainLocked hands over everything collected so far and starts a fresh batch, with the nil
// slices replaced by empty ones so PHP always decodes two lists instead of two nulls. The
// entry's lock must be held.
func (e *channelEntry) drainLocked() payloads.WaitResult {
	result := payloads.WaitResult{
		Confirmations: e.confirmations,
		Returns:       e.returns,
	}

	e.confirmations = nil
	e.returns = nil

	if result.Confirmations == nil {
		result.Confirmations = []payloads.Confirmation{}
	}

	if result.Returns == nil {
		result.Returns = []payloads.ReturnedMessage{}
	}

	return result
}
