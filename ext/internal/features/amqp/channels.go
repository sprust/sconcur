package amqp_feature

import (
	"context"
	"errors"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	"sconcur/internal/features/amqp/payloads"

	amqp091 "github.com/rabbitmq/amqp091-go"
)

const (
	// channelIdleTTL: a channel with no consumers that has run no command for this long
	// is closed. It is the safety net for the channels an application dropped without
	// closing in a way PHP could not report (a fatal error, a killed worker) — the usual
	// path is Channel::close() or its destructor.
	channelIdleTTL       = 30 * time.Minute
	channelSweepInterval = time.Minute
)

var errChannelNotFound = errors.New("unknown channel")

// channelCounter backs the channel handle ids.
var channelCounter atomic.Int64

var channelsOnce sync.Once
var channelsInstance *channels

// channels is the process-wide registry of open channels. It is global on purpose: an
// acknowledgement may well arrive from another coroutine, and so from another flow, than
// the consumer that received the message.
//
// It is the only registry a channel is in. Which channels a connection handle owns is
// answered by walking this one — the handle kept a map of its own once, and keeping the
// two in step meant every open and every close taking both locks in a fixed order.
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
	return boundedResult(
		ctx,
		func() (*channelEntry, error) {
			return c.open(handle, params)
		},
		func(entry *channelEntry) {
			c.close(entry.id)
		},
	)
}

// open opens a channel on the handle's connection and registers it.
func (c *channels) open(handle *connectionHandle, params payloads.ChannelOpenParams) (*channelEntry, error) {
	channel, err := handle.pooled.connection.Channel()

	if err != nil {
		return nil, err
	}

	entry := newChannelEntry(nextChannelId(), channel, handle)

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

	// Counted, not derived from how many channels the handle has: closing a channel would
	// otherwise hand the next one a number another live channel already has.
	handle.channelCounter++

	entry.number = handle.channelCounter

	// Registered while the handle's lock is held: a dropHandle in between would otherwise
	// walk the registry, miss this entry, and find it inserted right after.
	c.mutex.Lock()
	c.entries[entry.id] = entry
	c.mutex.Unlock()

	handle.mutex.Unlock()

	// One goroutine reads everything the driver reports about this channel: the messages
	// it returned, the publisher confirms once confirm mode is on, and the close that ends
	// it. Returns are collected from the moment the channel opens — a mandatory publish
	// may be made on a channel that never enters confirm mode, and the driver's listener
	// has to be drained either way.
	go entry.collect(
		channel.NotifyReturn(make(chan amqp091.Return, returnQueueSize)),
		channel.NotifyClose(make(chan *amqp091.Error, 1)),
	)

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

// close closes one channel and drops it from the registry.
func (c *channels) close(channelId string) {
	c.mutex.Lock()

	entry, exists := c.entries[channelId]

	delete(c.entries, channelId)

	c.mutex.Unlock()

	if !exists {
		return
	}

	entry.close()
}

// usedChannels counts the channels of one connection handle.
func (c *channels) usedChannels(handle *connectionHandle) int {
	c.mutex.RLock()
	defer c.mutex.RUnlock()

	count := 0

	for _, entry := range c.entries {
		if entry.handle == handle {
			count++
		}
	}

	return count
}

// dropHandle closes every channel of a connection handle — the handle was released, or
// its connection died.
func (c *channels) dropHandle(handle *connectionHandle) {
	c.mutex.Lock()

	var entries []*channelEntry

	for id, entry := range c.entries {
		if entry.handle != handle {
			continue
		}

		entries = append(entries, entry)

		delete(c.entries, id)
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

func (c *channels) startSweeper() {
	go func() {
		// Never stopped: the sweeper runs for the life of the process, so a deferred
		// stop would only read as if there were a way out of this loop.
		ticker := time.NewTicker(channelSweepInterval)

		for range ticker.C {
			c.sweep()
		}
	}()
}

func (c *channels) sweep() {
	for _, entry := range c.collectExpired(time.Now()) {
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

// applyQos sends the per-consumer prefetch settings of a freshly opened channel. The
// channel-wide form is a Qos command of its own: writing the per-consumer limits clears it,
// so setting both at open time only ever meant sending one to overwrite the other.
func applyQos(channel *amqp091.Channel, params payloads.ChannelOpenParams) error {
	return channel.Qos(params.PrefetchCount, params.PrefetchSizeBytes, false)
}

func nextChannelId() string {
	return "amqp:ch:" + strconv.FormatInt(channelCounter.Add(1), 10)
}
