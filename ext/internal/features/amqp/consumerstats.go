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
}

var consumerStatsInstance = &consumerStats{
	inFlight: make(map[deliveryKey]time.Time),
}

var pusherOnce sync.Once
var consumerPusher *stats.Pusher

// startConsumerTelemetry brings up the worker's pusher the first time a consumer
// opens, and never again — one pusher per process, like one per server. The collector
// address and the pool name come from the environment the master gives every worker,
// so nothing about this crosses the PHP boundary.
func startConsumerTelemetry() {
	pusherOnce.Do(func() {
		socketPath := os.Getenv(telemetrySocketEnv)

		if socketPath == "" {
			return
		}

		intervalMs, _ := strconv.Atoi(os.Getenv(telemetryIntervalMs))

		consumerPusher = stats.NewPusher(
			os.Getenv(telemetryNameEnv),
			socketPath,
			intervalMs,
			time.Now(),
			consumerStatsInstance,
		)

		consumerPusher.Start()
	})
}

// stopConsumerTelemetry ends the push loop; called from the feature's Shutdown.
func stopConsumerTelemetry() {
	if consumerPusher != nil {
		consumerPusher.Stop()

		consumerPusher = nil
	}
}

// deliveryDispatched records a delivery on its way to PHP. An auto-acknowledged one is
// settled on the spot: no acknowledgement will ever come back for it, and leaving it in
// flight would grow the map for the life of the process.
func (c *consumerStats) deliveryDispatched(channelId string, deliveryTag uint64, autoAck bool) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	c.delivered++

	if autoAck {
		c.acked++
		c.settledCount++

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

	if acked {
		c.acked++
	} else {
		c.refused++
	}

	now := time.Now()

	// "Up to and including this tag" settles every earlier delivery of that channel
	// too, which is the whole point of AMQP_MULTIPLE.
	if multiple {
		for key, startedAt := range c.inFlight {
			if key.channelId == channelId && key.deliveryTag <= deliveryTag {
				c.recordSettledLocked(now.Sub(startedAt))

				delete(c.inFlight, key)
			}
		}

		return
	}

	key := deliveryKey{channelId: channelId, deliveryTag: deliveryTag}

	startedAt, exists := c.inFlight[key]

	if !exists {
		return
	}

	c.recordSettledLocked(now.Sub(startedAt))

	delete(c.inFlight, key)
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

	if c.delivered == 0 && len(c.inFlight) == 0 {
		return stats.Workload{}
	}

	consumers := &stats.Consumers{
		Delivered: c.delivered,
		Acked:     c.acked,
		Refused:   c.refused,
		InFlight:  len(c.inFlight),
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
