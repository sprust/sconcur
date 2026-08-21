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

	mutex     sync.Mutex
	consumers map[string]context.CancelFunc
	closed    bool

	// lastUsedAt is what the idle sweeper looks at.
	lastUsedAt time.Time

	confirming    bool
	pending       int
	confirmations []payloads.Confirmation
	returns       []payloads.ReturnedMessage
	// notify wakes the wait loops on every confirm or return; buffered, so the
	// collectors never block on a waiter that is not there.
	notify chan struct{}
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
		consumers:  make(map[string]context.CancelFunc),
		lastUsedAt: time.Now(),
		notify:     make(chan struct{}, 1),
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

	entry.number = len(handle.channels) + 1

	handle.channels[entry.id] = entry

	handle.mutex.Unlock()

	c.mutex.Lock()
	c.entries[entry.id] = entry
	c.mutex.Unlock()

	// Returned messages are collected from the moment the channel opens: an application
	// may publish with AMQP_MANDATORY and wait for the returns without ever putting the
	// channel into publisher-confirm mode.
	go entry.collectReturns(channel.NotifyReturn(make(chan amqp091.Return, returnQueueSize)))

	return entry, nil
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

	for _, entry := range entries {
		entry.close()
	}
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

	cancels := make([]context.CancelFunc, 0, len(e.consumers))

	for _, cancel := range e.consumers {
		cancels = append(cancels, cancel)
	}

	e.consumers = make(map[string]context.CancelFunc)

	e.mutex.Unlock()

	for _, cancel := range cancels {
		cancel()
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

func (e *channelEntry) registerConsumer(consumerTag string, cancel context.CancelFunc) {
	e.mutex.Lock()
	defer e.mutex.Unlock()

	e.consumers[consumerTag] = cancel
	e.lastUsedAt = time.Now()
}

func (e *channelEntry) forgetConsumer(consumerTag string) {
	e.mutex.Lock()

	cancel, exists := e.consumers[consumerTag]

	delete(e.consumers, consumerTag)

	e.lastUsedAt = time.Now()

	e.mutex.Unlock()

	if exists {
		cancel()
	}
}

// startConfirmMode puts the channel into publisher-confirm mode and starts collecting
// what the broker reports. Calling it twice is a no-op.
func (e *channelEntry) startConfirmMode(noWait bool) error {
	e.mutex.Lock()

	if e.confirming {
		e.mutex.Unlock()

		return nil
	}

	e.mutex.Unlock()

	e.channelMutex.Lock()

	err := e.channel.Confirm(noWait)

	var confirms chan amqp091.Confirmation

	if err == nil {
		confirms = e.channel.NotifyPublish(make(chan amqp091.Confirmation, confirmQueueSize))
	}

	e.channelMutex.Unlock()

	if err != nil {
		return err
	}

	e.mutex.Lock()
	e.confirming = true
	e.mutex.Unlock()

	go e.collectConfirms(confirms)

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

func (e *channelEntry) wake() {
	select {
	case e.notify <- struct{}{}:
	default:
	}
}

// waitForConfirms waits until every message published since the last wait has been
// confirmed or rejected, and returns everything collected on the way, the returned
// messages included.
func (e *channelEntry) waitForConfirms(ctx context.Context, timeout time.Duration) (payloads.WaitResult, error) {
	return e.wait(ctx, timeout, func() bool {
		return !e.confirming || e.pending == 0
	}, "timeout waiting for publisher confirms")
}

// waitForReturns waits until the broker has returned at least one message.
func (e *channelEntry) waitForReturns(ctx context.Context, timeout time.Duration) (payloads.WaitResult, error) {
	return e.wait(ctx, timeout, func() bool {
		return len(e.returns) > 0
	}, "timeout waiting for returned messages")
}

// wait blocks until the condition holds (checked under the entry's lock), the deadline
// passes, or the flow stops, and hands back what the collectors gathered.
func (e *channelEntry) wait(
	ctx context.Context,
	timeout time.Duration,
	ready func() bool,
	timeoutMessage string,
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
			result := e.drainLocked()

			e.mutex.Unlock()

			return result, nil
		}

		e.mutex.Unlock()

		select {
		case <-e.notify:
		case <-deadline:
			return payloads.WaitResult{}, errors.New(timeoutMessage)
		case <-ctx.Done():
			return payloads.WaitResult{}, ctx.Err()
		}
	}
}

// drainLocked hands over everything collected so far and starts a fresh batch. The
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
