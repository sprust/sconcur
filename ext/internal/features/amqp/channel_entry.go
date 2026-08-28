package amqp_feature

import (
	"context"
	"sync"
	"time"

	"sconcur/internal/features/amqp/payloads"

	amqp091 "github.com/rabbitmq/amqp091-go"
)

const (
	channelCloseTimeout = 5 * time.Second

	// consumerCancelTimeout bounds the basic.cancel sent while a consumer is torn down.
	consumerCancelTimeout = 5 * time.Second
)

// channelEntry is one open channel: the driver channel itself, the handle that owns it,
// its consumers, and — once the channel is in publisher-confirm mode — what the broker
// has reported about the messages published on it.
//
// The confirm-mode half lives in confirms.go, beside the two commands that drive it.
type channelEntry struct {
	id     string
	number int
	handle *connectionHandle

	// channelMutex serializes the driver channel: a Recover or a Cancel may arrive from
	// another coroutine while a command is running.
	channelMutex sync.Mutex
	channel      *amqp091.Channel

	mutex sync.Mutex
	// consumers maps the tags this channel has open onto the key of the stream each is
	// read through, so cancelling a consumer can drop that stream as well. A consumer is
	// cancelled through the driver channel like any other command, not by a context the
	// driver watches on its own.
	consumers map[string]string
	closed    bool
	// closeReason is what the broker said when it closed the channel, recorded by the
	// collector before the channel is dropped. It is what turns "No channel available."
	// into the 404 or 406 that actually happened — see classify.
	closeReason *amqp091.Error

	// lastUsedAt is what the idle sweeper looks at.
	lastUsedAt time.Time

	confirming    bool
	pending       int
	confirmations []payloads.Confirmation
	returns       []payloads.ReturnedMessage

	// changed is closed and replaced whenever something a wait loop cares about happens.
	// A waiter takes the current one under the lock and selects on it, so there is no
	// registration to undo: a wait that ended on its own leaves nothing behind, and a
	// channel polled by a wait that is never fed cannot accumulate dead waiters.
	changed chan struct{}
	// gone is closed once the channel is, so a wait loop ends instead of parking
	// forever on a channel the broker took away.
	gone chan struct{}
	// confirmsReady hands the publisher-confirm listener to the collector goroutine that
	// is already reading the returns. One goroutine reads both, because the two are
	// ordered on the wire and must stay ordered here: see collect.
	confirmsReady chan chan amqp091.Confirmation
}

func newChannelEntry(id string, channel *amqp091.Channel, handle *connectionHandle) *channelEntry {
	return &channelEntry{
		id:         id,
		channel:    channel,
		handle:     handle,
		consumers:  make(map[string]string),
		lastUsedAt: time.Now(),
		changed:    make(chan struct{}),
		gone:       make(chan struct{}),
		// Buffered, so the coroutine that turns confirm mode on hands the listener over
		// without waiting for the collector to come round to it.
		confirmsReady: make(chan chan amqp091.Confirmation, 1),
	}
}

func (e *channelEntry) touch() {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	e.lastUsedAt = time.Now()
}

func (e *channelEntry) isClosed() bool {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	return e.closed
}

// connectionClosed answers whether the connection this channel lives on has gone away.
//
// The driver marks a channel closed inside the connection's reader before the collector
// here has seen the NotifyClose, so e.closed reads false for the whole of that window —
// and a channel-level 404 answered in it would be classified as a dead connection, taking
// every other channel of that connection down with it. The connection is the one thing
// that cannot be raced: amqp091 marks it closed in shutdown(), before it goes on to the
// channels.
func (e *channelEntry) connectionClosed() bool {
	if e.handle == nil || e.handle.pooled == nil || e.handle.pooled.connection == nil {
		return false
	}

	return e.handle.pooled.connection.IsClosed()
}

// takeCloseReason answers what the broker said when it closed this channel, or nil when
// nobody said anything — the channel was released from this side, or it is not closed.
func (e *channelEntry) takeCloseReason() *amqp091.Error {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	return e.closeReason
}

func (e *channelEntry) isIdleSince(moment time.Time) bool {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	return len(e.consumers) == 0 && e.lastUsedAt.Before(moment)
}

// close cancels the consumers of the channel and closes it, under a fresh deadline: what
// triggered the close may already be cancelled.
func (e *channelEntry) close() {
	e.mutex.Lock()

	if e.closed {
		e.mutex.Unlock()

		return
	}

	e.closed = true

	// The consumers are simply forgotten: closing the channel ends them on the broker,
	// and a basic.cancel sent alongside the close would arrive out of order.
	e.consumers = make(map[string]string)

	e.mutex.Unlock()

	// Whatever PHP was still holding on this channel is gone with it: the broker has
	// taken those deliveries back, so they must not stay counted as in flight.
	consumerStatsInstance.channelGone(e.id)

	// Everything parked on this channel ends now: a wait loop with no deadline would
	// otherwise sit here for the life of the process.
	close(e.gone)

	bounded(channelCloseTimeout, func() {
		e.channelMutex.Lock()
		defer e.channelMutex.Unlock()

		_ = e.channel.Close()
	})
}

// do runs one driver call on the channel, serialized against the other commands of this
// channel and bounded by the command context: the amqp091 methods take no context of
// their own, so the call runs on its own goroutine and the task stops waiting when the
// deadline passes or the flow stops. The call itself is left to finish — the next command
// on this channel simply waits its turn.
func (e *channelEntry) do(ctx context.Context, call func(channel *amqp091.Channel) error) error {
	return e.doAbandoning(ctx, call, nil)
}

// doAbandoning is do() for the calls whose result must not simply be dropped when the
// caller stops waiting. The call is left to finish either way, but what it produced is
// handed to abandon() on its own goroutine — a consumer the broker registered after the
// deadline has to be cancelled, and a message it handed over has to go back, or both are
// lost with nobody the wiser.
func (e *channelEntry) doAbandoning(
	ctx context.Context,
	call func(channel *amqp091.Channel) error,
	abandon func(err error),
) error {
	done := make(chan error, 1)

	go func() {
		e.channelMutex.Lock()
		defer e.channelMutex.Unlock()

		done <- call(e.channel)
	}()

	select {
	case err := <-done:
		return err
	case <-ctx.Done():
		if abandon != nil {
			go abandon(<-done)
		}

		return ctx.Err()
	}
}

func (e *channelEntry) registerConsumer(consumerTag string, taskKey string) {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	e.consumers[consumerTag] = taskKey
	e.lastUsedAt = time.Now()
}

// forgetConsumer drops a consumer from the registry and reports the key of the stream it
// was read through, so a cancelled consumer takes that stream with it. The second answer
// is false when the consumer was gone already, and a tag is never cancelled twice.
func (e *channelEntry) forgetConsumer(consumerTag string) (string, bool) {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	taskKey, exists := e.consumers[consumerTag]

	delete(e.consumers, consumerTag)

	e.lastUsedAt = time.Now()

	return taskKey, exists
}

// cancelConsumer sends the basic.cancel for a consumer this feature still holds, on a
// fresh context: by the time a stream is torn down its task context is long gone. It runs
// through do(), so it is serialized against every other command on the channel — a cancel
// racing a channel close is what makes a broker complain about an unexpected command.
func (e *channelEntry) cancelConsumer(consumerTag string) {
	if _, exists := e.forgetConsumer(consumerTag); !exists {
		return
	}

	e.sendCancel(consumerTag, false)
}

// sendCancel is the basic.cancel itself, without the registry check — for a consumer the
// broker accepted but this side never registered, which is what a registration that
// outran its deadline leaves behind.
func (e *channelEntry) sendCancel(consumerTag string, noWait bool) {
	if e.isClosed() {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), consumerCancelTimeout)
	defer cancel()

	_ = e.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Cancel(consumerTag, noWait)
	})
}

// bounded runs a call that takes no context of its own and stops waiting for it after the
// timeout. The call is left to finish; only the wait ends.
//
// Every driver method is like this — amqp091 takes no contexts — so each place that has to
// put a deadline on one was the same goroutine, buffered channel and select, written out by
// hand.
func bounded(timeout time.Duration, call func()) {
	done := make(chan struct{})

	go func() {
		defer close(done)

		call()
	}()

	timer := time.NewTimer(timeout)
	defer timer.Stop()

	select {
	case <-done:
	case <-timer.C:
	}
}

// boundedResult is bounded() for a call that produces something. What a call that finished
// after the wait gave up produced is handed to abandon(), so a connection or a channel that
// arrived too late is released rather than leaked.
func boundedResult[T comparable](
	ctx context.Context,
	call func() (T, error),
	abandon func(value T),
) (T, error) {
	type outcome struct {
		value T
		err   error
	}

	results := make(chan outcome, 1)

	go func() {
		value, err := call()

		results <- outcome{value: value, err: err}
	}()

	var zero T

	select {
	case result := <-results:
		return result.value, result.err
	case <-ctx.Done():
		go func() {
			result := <-results

			if result.value != zero {
				abandon(result.value)
			}
		}()

		return zero, ctx.Err()
	}
}
