package amqp_feature

import (
	"os"
	"sconcur/internal/stats"
	"strconv"
	"sync"
	"time"
)

// The buckets a delivery in flight falls into, by how long PHP has been holding it.
// Exclusive, and the same thresholds the HTTP server uses, so a panel reads the two
// sections the same way.
const (
	inFlightWarnAfter   = time.Second
	inFlightSlowAfter   = 5 * time.Second
	inFlightStuckAfter  = 15 * time.Second
	telemetryNameEnv    = "SCONCUR_SERVER_NAME"
	telemetrySocketEnv  = "SCONCUR_TELEMETRY_SOCKET"
	telemetryIntervalMs = "SCONCUR_TELEMETRY_INTERVAL_MS"

	// defaultPoolName labels the snapshots of a worker nobody named — the same default
	// the servers carry in their constructor.
	defaultPoolName = "sconcur-server"
)

// deliveryKey identifies one unsettled delivery. The tag is only unique within its
// channel, so the channel is part of the key.
type deliveryKey struct {
	channelId   string
	deliveryTag uint64
}

// consumerStats is the worker's queue-consumer telemetry, read off the traffic that
// already crosses the boundary rather than reported separately: a delivery is counted
// when it is handed to PHP, and settled when the acknowledgement or the refusal comes
// back as an ordinary command. "The job is done, or it is not" is basic.ack against
// basic.nack, and both already travel.
//
// It is the WorkloadProvider of the process-wide pusher, so the numbers are the
// worker's, not one consumer's.
type consumerStats struct {
	mutex sync.Mutex

	delivered int64
	acked     int64
	refused   int64

	settledCount   int64
	settledTotalMs float64

	inFlight map[deliveryKey]time.Time

	// live is the set of consumers this worker has open, one per coroutine. A set
	// rather than a counter because a consumer can be closed by either its own
	// cleanup or the death of its channel, and both must be able to run.
	live map[consumerKey]struct{}
}

// consumerKey identifies one open consumer. A tag is only unique within its channel.
type consumerKey struct {
	channelId   string
	consumerTag string
}

var consumerStatsInstance = &consumerStats{
	inFlight: make(map[deliveryKey]time.Time),
	live:     make(map[consumerKey]struct{}),
}

var pusherMutex sync.Mutex
var consumerPusher *stats.Pusher

// startConsumerTelemetry brings up the worker's pusher the first time a consumer
// opens, and never again — one pusher per process, like one per server. The collector
// address and the pool name come from the environment the master gives every worker,
// so nothing about this crosses the PHP boundary.
func startConsumerTelemetry() {
	pusherMutex.Lock()
	defer pusherMutex.Unlock()

	if consumerPusher != nil {
		return
	}

	socketPath := os.Getenv(telemetrySocketEnv)

	if socketPath == "" {
		return
	}

	intervalMs, _ := strconv.Atoi(os.Getenv(telemetryIntervalMs))

	name := os.Getenv(telemetryNameEnv)

	// A snapshot with no name is dropped by the collector without a word, so a worker
	// started outside a master — which sets the label — would push every interval and
	// never appear. The servers default theirs the same way.
	if name == "" {
		name = defaultPoolName
	}

	consumerPusher = stats.NewPusher(
		name,
		socketPath,
		intervalMs,
		time.Now(),
		consumerStatsInstance,
	)

	consumerPusher.Start()
}

// stopConsumerTelemetry ends the push loop; called from the feature's Shutdown.
// A pusher stopped here can be started again: destroy() leaves the handler usable, and a
// consumer opened after it must report like any other.
func stopConsumerTelemetry() {
	pusherMutex.Lock()
	defer pusherMutex.Unlock()

	if consumerPusher != nil {
		consumerPusher.Stop()

		consumerPusher = nil
	}
}

// consumerOpened records a coroutine that started consuming.
func (c *consumerStats) consumerOpened(channelId string, consumerTag string) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	c.live[consumerKey{channelId: channelId, consumerTag: consumerTag}] = struct{}{}
}

// consumerClosed records one that stopped, whichever of the two paths got there first.
func (c *consumerStats) consumerClosed(channelId string, consumerTag string) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	delete(c.live, consumerKey{channelId: channelId, consumerTag: consumerTag})
}

// deliveryDispatched records a delivery on its way to PHP. An auto-acknowledged one is
// settled on the spot: no acknowledgement will ever come back for it, and leaving it in
// flight would grow the map for the life of the process.
func (c *consumerStats) deliveryDispatched(channelId string, deliveryTag uint64, autoAck bool) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	c.delivered++

	if autoAck {
		// No acknowledgement will come back, so there is no handler time to measure:
		// counted as settled, left out of the average it would otherwise pull to zero.
		c.acked++

		return
	}

	c.inFlight[deliveryKey{channelId: channelId, deliveryTag: deliveryTag}] = time.Now()
}

// deliverySettled records the acknowledgement or the refusal of a delivery. A tag this
// worker never handed out — a message pulled with basic.get, or one settled twice — is
// counted for what it is and does not disturb the in-flight set.
func (c *consumerStats) deliverySettled(channelId string, deliveryTag uint64, multiple bool, acked bool) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	now := time.Now()

	settled := 0

	// "Up to and including this tag" settles every earlier delivery of that channel
	// too, which is the whole point of a multiple ack. The counters follow deliveries,
	// not commands: one multiple-ack of a hundred messages is a hundred settled, and
	// counting it as one would have the panel report 99% of them unacknowledged.
	if multiple {
		for key, startedAt := range c.inFlight {
			if key.channelId == channelId && key.deliveryTag <= deliveryTag {
				c.recordSettledLocked(now.Sub(startedAt))

				delete(c.inFlight, key)

				settled++
			}
		}
	} else {
		key := deliveryKey{channelId: channelId, deliveryTag: deliveryTag}

		if startedAt, exists := c.inFlight[key]; exists {
			c.recordSettledLocked(now.Sub(startedAt))

			delete(c.inFlight, key)

			settled = 1
		}
	}

	// A tag this worker never handed out — a message pulled with basic.get, or one
	// settled twice — still settled something as far as the broker is concerned.
	if settled == 0 {
		settled = 1
	}

	if acked {
		c.acked += int64(settled)
	} else {
		c.refused += int64(settled)
	}
}

// channelGone drops whatever a dead channel was still holding. A handler that threw
// without settling its message leaves it here, and the broker has taken it back — so
// keeping it in flight would only inflate the number forever.
func (c *consumerStats) channelGone(channelId string) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	for key := range c.inFlight {
		if key.channelId == channelId {
			delete(c.inFlight, key)
		}
	}

	for key := range c.live {
		if key.channelId == channelId {
			delete(c.live, key)
		}
	}
}

func (c *consumerStats) recordSettledLocked(took time.Duration) {
	c.settledCount++
	c.settledTotalMs += float64(took.Nanoseconds()) / 1e6
}

// WorkloadSnapshot answers the pusher. A worker that has consumed nothing reports no
// section at all, so a snapshot never claims a workload it does not have.
func (c *consumerStats) WorkloadSnapshot() stats.Workload {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	// Nothing at all has happened: no section, so a snapshot never claims a workload it
	// does not have. Anything at all — including an acknowledgement of a tag this worker
	// never handed out, which is what a basic.get followed by an ack looks like — is
	// worth reporting.
	if c.delivered == 0 && c.acked == 0 && c.refused == 0 && len(c.inFlight) == 0 && len(c.live) == 0 {
		return stats.Workload{}
	}

	consumers := &stats.Consumers{
		Coroutines: len(c.live),
		Delivered:  c.delivered,
		Acked:      c.acked,
		Refused:    c.refused,
		Timed:      c.settledCount,
		InFlight:   len(c.inFlight),
	}

	if c.settledCount > 0 {
		consumers.AvgMs = c.settledTotalMs / float64(c.settledCount)
	}

	now := time.Now()

	for _, startedAt := range c.inFlight {
		switch age := now.Sub(startedAt); {
		case age >= inFlightStuckAfter:
			consumers.InFlightOver15s++
		case age >= inFlightSlowAfter:
			consumers.InFlight5to15s++
		case age >= inFlightWarnAfter:
			consumers.InFlight1to5s++
		}
	}

	return stats.Workload{Consumers: consumers}
}
