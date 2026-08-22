package amqp_feature

import (
	"context"
	"errors"
	"sconcur/internal/features/amqp/payloads"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
)

const (
	// channelIdleTTL: a channel with no consumers that has run no command for this long
	// is closed. It is the safety net for the channels an application dropped without
	// closing in a way PHP could not report (a fatal error, a killed worker) — the usual
	// path is AMQPChannel::close() or its destructor.
	channelIdleTTL       = 30 * time.Minute
	channelSweepInterval = time.Minute
	channelCloseTimeout  = 5 * time.Second

	// confirmQueueSize buffers the publisher confirms the broker has sent but
	// waitForConfirm() has not collected yet.
	confirmQueueSize = 1024
	// returnQueueSize buffers the messages the broker returned as unroutable.
	returnQueueSize = 128
)

var errChannelNotFound = errors.New("unknown channel")

// consumerCancelTimeout bounds the basic.cancel sent while a consumer is torn down.
const consumerCancelTimeout = 5 * time.Second

// errWaitTimeout is what a wait loop reports when its deadline passes. The wording is the
// extension's, and it reaches PHP unwrapped (see classify).
var errWaitTimeout = errors.New("Wait timeout exceed")

// channelCounter backs the channel handle ids.
var channelCounter atomic.Int64

// channelEntry is one open channel: the driver channel itself, the handle that owns it,
// its consumers, and — once the channel is in publisher-confirm mode — what the broker
// has reported about the messages published on it.
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

	// lastUsedAt is what the idle sweeper looks at.
	lastUsedAt time.Time

	confirming bool
	// confirmClaimed is set the moment a coroutine starts putting the channel into
	// confirm mode, so a second one does not register another listener.
	confirmClaimed bool
	pending        int
	confirmations  []payloads.Confirmation
	returns        []payloads.ReturnedMessage
	// waiters are the wait loops parked on this channel, woken on every confirm and
	// every return. One channel per waiter: a shared one would let a confirm wake the
	// loop waiting for a return, which would then park again and miss its own event.
	waiters []chan struct{}
	// gone is closed once the channel is, so a wait loop ends instead of parking
	// forever on a channel the broker took away.
	gone chan struct{}
}

var channelsOnce sync.Once
var channelsInstance *channels

// channels is the process-wide registry of open channels. It is global on purpose: an
// acknowledgement may well arrive from another coroutine, and so from another flow, than
// the consumer that received the message.
type channels struct {
	mutex   sync.RWMutex
	entries map[string]*channelEntry
}

func getChannels() *channels {
	channelsOnce.Do(func() {
		channelsInstance = &channels{
			entries: make(map[string]*channelEntry),
		}

		channelsInstance.startSweeper()
	})

	return channelsInstance
}

// openBounded opens a channel under the command deadline. Opening a channel talks to the
// broker (channel.open plus the basic.qos that carries the prefetch settings), and the
// driver's calls take no context, so the work runs on its own goroutine and a channel that
// arrives after the deadline is closed rather than leaked.
func (c *channels) openBounded(
	ctx context.Context,
	handle *connectionHandle,
	params payloads.ChannelOpenParams,
) (*channelEntry, error) {
	type opened struct {
		entry *channelEntry
		err   error
	}

	results := make(chan opened, 1)

	go func() {
		entry, err := c.open(handle, params)

		results <- opened{entry: entry, err: err}
	}()

	select {
	case result := <-results:
		return result.entry, result.err
	case <-ctx.Done():
		go func() {
			result := <-results

			if result.entry != nil {
				c.close(result.entry.id)
			}
		}()

		return nil, ctx.Err()
	}
}

// open opens a channel on the handle's connection and registers it.
func (c *channels) open(handle *connectionHandle, params payloads.ChannelOpenParams) (*channelEntry, error) {
	channel, err := handle.pooled.connection.Channel()

	if err != nil {
		return nil, err
	}

	entry := &channelEntry{
		id:         nextChannelId(),
		channel:    channel,
		handle:     handle,
		consumers:  make(map[string]string),
		lastUsedAt: time.Now(),
		gone:       make(chan struct{}),
	}

	if err := applyQos(channel, params); err != nil {
		_ = channel.Close()

		return nil, err
	}

	handle.mutex.Lock()

	if handle.closed {
		handle.mutex.Unlock()

		_ = channel.Close()

		return nil, errors.New("connection handle is released")
	}

	// Counted, not derived from the map size: closing a channel would otherwise hand the
	// next one an id another live channel already has.
	handle.channelCounter++

	entry.number = handle.channelCounter

	handle.channels[entry.id] = entry

	// Registered before the handle's lock is released: a dropHandle in between would
	// otherwise close this entry and then find it inserted right after.
	c.mutex.Lock()
	c.entries[entry.id] = entry
	c.mutex.Unlock()

	handle.mutex.Unlock()

	// Returned messages are collected from the moment the channel opens: an application
	// may publish with AMQP_MANDATORY and wait for the returns without ever putting the
	// channel into publisher-confirm mode.
	go entry.collectReturns(channel.NotifyReturn(make(chan amqp091.Return, returnQueueSize)))

	// A protocol error (a passive declare of a queue that does not exist, a publish to a
	// missing exchange) makes the broker close the channel. Without this watcher the dead
	// channel would sit in the registries until the idle sweeper, answering commands with
	// a confusing error and counting towards the connection's channel limit.
	c.watch(entry, channel.NotifyClose(make(chan *amqp091.Error, 1)))

	return entry, nil
}

// watch drops a channel from the registries as soon as the broker or the connection ends
// it, and wakes whoever is waiting on it.
func (c *channels) watch(entry *channelEntry, closed chan *amqp091.Error) {
	go func() {
		<-closed

		c.close(entry.id)
	}()
}

// find returns the channel behind an id, marking it as used so the idle sweeper leaves it
// alone.
func (c *channels) find(channelId string) (*channelEntry, error) {
	c.mutex.RLock()

	entry, exists := c.entries[channelId]

	c.mutex.RUnlock()

	if !exists {
		return nil, errChannelNotFound
	}

	entry.touch()

	return entry, nil
}

// close closes one channel and drops it from both registries.
func (c *channels) close(channelId string) {
	c.mutex.Lock()

	entry, exists := c.entries[channelId]

	delete(c.entries, channelId)

	c.mutex.Unlock()

	if !exists {
		return
	}

	c.forget(entry)

	entry.close()
}

// dropHandle closes every channel of a connection handle — the handle was released, or
// its connection died.
func (c *channels) dropHandle(handle *connectionHandle) {
	handle.mutex.Lock()

	entries := make([]*channelEntry, 0, len(handle.channels))

	for _, entry := range handle.channels {
		entries = append(entries, entry)
	}

	handle.channels = make(map[string]*channelEntry)

	handle.mutex.Unlock()

	c.mutex.Lock()

	for _, entry := range entries {
		delete(c.entries, entry.id)
	}

	c.mutex.Unlock()

	// Closed at the same time rather than one after another: each close waits up to
	// channelCloseTimeout, and a connection holding a dozen channels would otherwise make
	// a disconnect take a dozen timeouts.
	var closing sync.WaitGroup

	for _, entry := range entries {
		closing.Add(1)

		go func(entry *channelEntry) {
			defer closing.Done()

			entry.close()
		}(entry)
	}

	closing.Wait()
}

// forget removes a channel from the handle that owns it.
func (c *channels) forget(entry *channelEntry) {
	entry.handle.mutex.Lock()

	delete(entry.handle.channels, entry.id)

	entry.handle.mutex.Unlock()
}

func (c *channels) startSweeper() {
	go func() {
		ticker := time.NewTicker(channelSweepInterval)
		defer ticker.Stop()

		for range ticker.C {
			c.sweep()
		}
	}()
}

func (c *channels) sweep() {
	for _, entry := range c.collectExpired(time.Now()) {
		c.forget(entry)

		entry.close()
	}
}

// collectExpired removes and returns the channels with no consumers that have been idle
// longer than the TTL. Closing is left to the caller, outside the lock.
func (c *channels) collectExpired(now time.Time) []*channelEntry {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	var expired []*channelEntry

	for id, entry := range c.entries {
		if !entry.isIdleSince(now.Add(-channelIdleTTL)) {
			continue
		}

		expired = append(expired, entry)

		delete(c.entries, id)
	}

	return expired
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

	waiters := e.waiters
	e.waiters = nil

	e.mutex.Unlock()

	// Whatever PHP was still holding on this channel is gone with it: the broker has
	// taken those deliveries back, so they must not stay counted as in flight.
	consumerStatsInstance.channelGone(e.id)

	// Everything parked on this channel ends now: a wait loop with no deadline would
	// otherwise sit here for the life of the process.
	close(e.gone)

	for _, waiter := range waiters {
		close(waiter)
	}

	done := make(chan struct{})

	go func() {
		e.channelMutex.Lock()

		_ = e.channel.Close()

		e.channelMutex.Unlock()

		close(done)
	}()

	ctx, cancel := context.WithTimeout(context.Background(), channelCloseTimeout)
	defer cancel()

	select {
	case <-done:
	case <-ctx.Done():
	}
}

// do runs one driver call on the channel, serialized against the other commands of this
// channel and bounded by the command context: the amqp091 methods take no context of
// their own, so the call runs on its own goroutine and the task stops waiting when the
// deadline passes or the flow stops. The call itself is left to finish — the next command
// on this channel simply waits its turn.
func (e *channelEntry) do(ctx context.Context, call func(channel *amqp091.Channel) error) error {
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
// was read through, so a cancelled consumer takes that stream with it. The empty string
// means the consumer was gone already, and a tag is never cancelled twice.
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

	if e.isClosed() {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), consumerCancelTimeout)
	defer cancel()

	_ = e.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Cancel(consumerTag, false)
	})
}

// startConfirmMode puts the channel into publisher-confirm mode and starts collecting what
// the broker reports. Calling it twice is a no-op: NotifyPublish appends listeners, so a
// second registration would count every confirmation twice.
//
// The claim on confirm mode is made under the entry's lock before the driver is called, so
// two coroutines racing on the same channel cannot both register.
func (e *channelEntry) startConfirmMode(ctx context.Context, noWait bool) error {
	e.mutex.Lock()

	if e.confirming || e.confirmClaimed {
		e.mutex.Unlock()

		return nil
	}

	e.confirmClaimed = true

	e.mutex.Unlock()

	err := e.do(ctx, func(channel *amqp091.Channel) error {
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

		// The collector starts here, beside the flag it belongs to, and not after do()
		// returns: do() stops waiting when the command deadline passes or the flow stops,
		// while this goroutine runs on. A channel left in confirm mode with nobody
		// draining the listener would strand every later waitForConfirm() on it (pending
		// would never fall back to zero) and, once the buffer filled, hold up the whole
		// connection's reader for the driver's notify timeout on every confirmation.
		go e.collectConfirms(confirms)

		return nil
	})

	if err != nil {
		// Given back only if confirm mode never engaged. The deadline may well have
		// passed while the broker was already answering, and that channel is in confirm
		// mode for good — handing the claim back there would let the next caller register
		// a second listener and count every confirmation twice.
		e.mutex.Lock()

		if !e.confirming {
			e.confirmClaimed = false
		}

		e.mutex.Unlock()

		return err
	}

	return nil
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

func (e *channelEntry) collectConfirms(confirms chan amqp091.Confirmation) {
	for confirmation := range confirms {
		e.mutex.Lock()

		e.confirmations = append(e.confirmations, payloads.Confirmation{
			DeliveryTag: confirmation.DeliveryTag,
			Acked:       confirmation.Ack,
		})

		if e.pending > 0 {
			e.pending--
		}

		e.mutex.Unlock()

		e.wake()
	}
}

func (e *channelEntry) collectReturns(returns chan amqp091.Return) {
	for returned := range returns {
		e.mutex.Lock()

		e.returns = append(e.returns, payloads.ReturnedMessage{
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
		})

		e.mutex.Unlock()

		e.wake()
	}
}

// wake releases every wait loop parked on this channel; each re-checks its own condition
// and parks again if it is not met.
func (e *channelEntry) wake() {
	e.mutex.Lock()

	waiters := e.waiters
	e.waiters = nil

	e.mutex.Unlock()

	for _, waiter := range waiters {
		close(waiter)
	}
}

// waiter parks the caller on this channel. The entry's lock must be held; the returned
// channel is closed by the next wake() or when the channel goes away.
func (e *channelEntry) waiterLocked() chan struct{} {
	waiter := make(chan struct{})

	e.waiters = append(e.waiters, waiter)

	return waiter
}

// dropWaiter unregisters a waiter whose wait ended on its own — a deadline, or a stopped
// flow — rather than on an event. Nothing but wake() clears the list otherwise, and a
// wake may never come: a channel polled with waitForBasicReturn() that is handed no
// returned message would collect one dead waiter per call for the rest of its life.
//
// A no-op when the waiter is already gone, which is the common race: a wake() that fired
// while this caller was choosing its select case took the whole list with it.
func (e *channelEntry) dropWaiter(waiter chan struct{}) {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	for index, registered := range e.waiters {
		if registered != waiter {
			continue
		}

		e.waiters = append(e.waiters[:index], e.waiters[index+1:]...)

		return
	}
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

// waitForReturns waits until the broker has returned at least one message. It leaves the
// publisher confirms where they are: taking them here would strand the wait loop that is
// counting on them.
func (e *channelEntry) waitForReturns(ctx context.Context, timeout time.Duration) (payloads.WaitResult, error) {
	return e.wait(ctx, timeout, func() bool {
		return len(e.returns) > 0
	}, e.drainReturnsLocked)
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

		// Registered before the lock is released: an event that fires in between wakes
		// this waiter instead of being missed.
		waiter := e.waiterLocked()

		e.mutex.Unlock()

		select {
		case <-waiter:
		case <-deadline:
			e.dropWaiter(waiter)

			return payloads.WaitResult{}, errWaitTimeout
		case <-e.gone:
			// No dropWaiter here: closing the channel already took the whole list.
			return payloads.WaitResult{}, amqp091.ErrClosed
		case <-ctx.Done():
			e.dropWaiter(waiter)

			return payloads.WaitResult{}, ctx.Err()
		}
	}
}

// drainLocked hands over everything collected so far and starts a fresh batch. The entry's
// lock must be held.
func (e *channelEntry) drainLocked() payloads.WaitResult {
	result := payloads.WaitResult{
		Confirmations: e.confirmations,
		Returns:       e.returns,
	}

	e.confirmations = nil
	e.returns = nil

	return normalizeWaitResult(result)
}

// drainReturnsLocked hands over the returned messages only. The entry's lock must be held.
func (e *channelEntry) drainReturnsLocked() payloads.WaitResult {
	result := payloads.WaitResult{
		Returns: e.returns,
	}

	e.returns = nil

	return normalizeWaitResult(result)
}

// normalizeWaitResult replaces the nil slices with empty ones, so PHP always decodes two
// lists instead of two nulls.
func normalizeWaitResult(result payloads.WaitResult) payloads.WaitResult {
	if result.Confirmations == nil {
		result.Confirmations = []payloads.Confirmation{}
	}

	if result.Returns == nil {
		result.Returns = []payloads.ReturnedMessage{}
	}

	return result
}

// applyQos sends the prefetch settings of a freshly opened channel: the per-consumer
// limits first, then the channel-wide ones if any is set — exactly the order ext-amqp
// uses, since writing the per-consumer limits clears the channel-wide ones.
func applyQos(channel *amqp091.Channel, params payloads.ChannelOpenParams) error {
	if err := channel.Qos(params.PrefetchCount, params.PrefetchSizeBytes, false); err != nil {
		return err
	}

	if params.GlobalPrefetchCount == 0 && params.GlobalPrefetchSizeBytes == 0 {
		return nil
	}

	return channel.Qos(params.GlobalPrefetchCount, params.GlobalPrefetchSizeBytes, true)
}

func nextChannelId() string {
	return "amqp:ch:" + strconv.FormatInt(channelCounter.Add(1), 10)
}
