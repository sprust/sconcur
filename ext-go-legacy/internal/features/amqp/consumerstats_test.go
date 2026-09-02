package amqp_feature

import (
	"testing"
	"time"
)

// newTestConsumerStats builds a counter set of its own, so a test never disturbs the
// process-wide instance the pusher reads.
func newTestConsumerStats() *consumerStats {
	return &consumerStats{
		inFlight: make(map[deliveryKey]time.Time),
		live:     make(map[consumerKey]struct{}),
	}
}

func TestSettlingCountsDeliveriesRatherThanCommands(t *testing.T) {
	stats := newTestConsumerStats()

	for tag := uint64(1); tag <= 100; tag++ {
		stats.deliveryDispatched("ch1", tag, false)
	}

	// One acknowledgement, a hundred deliveries settled. Counting the command would
	// have the panel report ninety-nine per cent of them unacknowledged.
	stats.deliverySettled("ch1", 100, true, true)

	consumers := stats.WorkloadSnapshot().Consumers

	if consumers.Delivered != 100 {
		t.Fatalf("delivered = %d, want 100", consumers.Delivered)
	}

	if consumers.Acked != 100 {
		t.Fatalf("acked = %d, want 100", consumers.Acked)
	}

	if consumers.InFlight != 0 {
		t.Fatalf("in flight = %d, want 0", consumers.InFlight)
	}
}

func TestAMultipleAckSettlesOnlyItsOwnChannel(t *testing.T) {
	stats := newTestConsumerStats()

	stats.deliveryDispatched("ch1", 1, false)
	stats.deliveryDispatched("ch2", 1, false)

	stats.deliverySettled("ch1", 5, true, true)

	if inFlight := stats.WorkloadSnapshot().Consumers.InFlight; inFlight != 1 {
		t.Fatalf("in flight = %d, want the other channel's delivery to be left alone", inFlight)
	}
}

// A tag this worker never handed out — a message pulled with basic.get — still settled
// something as far as the broker is concerned.
func TestSettlingAnUntrackedTagIsStillCounted(t *testing.T) {
	stats := newTestConsumerStats()

	stats.deliverySettled("ch1", 7, false, false)

	consumers := stats.WorkloadSnapshot().Consumers

	if consumers.Refused != 1 {
		t.Fatalf("refused = %d, want 1", consumers.Refused)
	}

	if consumers.InFlight != 0 {
		t.Fatalf("in flight = %d, want 0", consumers.InFlight)
	}
}

// An auto-acknowledged delivery is settled the moment it leaves, so it has no handler
// time. Folding it in as zero would drag the average of a worker that mixes the two
// towards nothing.
func TestAutoAcknowledgedDeliveriesStayOutOfTheAverage(t *testing.T) {
	stats := newTestConsumerStats()

	for tag := uint64(1); tag <= 9; tag++ {
		stats.deliveryDispatched("ch1", tag, true)
	}

	stats.deliveryDispatched("ch1", 10, false)

	stats.mutex.Lock()
	stats.inFlight[deliveryKey{channelId: "ch1", deliveryTag: 10}] = time.Now().Add(-50 * time.Millisecond)
	stats.mutex.Unlock()

	stats.deliverySettled("ch1", 10, false, true)

	consumers := stats.WorkloadSnapshot().Consumers

	if consumers.Delivered != 10 || consumers.Acked != 10 {
		t.Fatalf("delivered/acked = %d/%d, want 10/10", consumers.Delivered, consumers.Acked)
	}

	if consumers.Timed != 1 {
		t.Fatalf("timed = %d, want only the manually settled one", consumers.Timed)
	}

	if consumers.AvgMs < 40 || consumers.AvgMs > 200 {
		t.Fatalf("avgMs = %f, want the manual delivery's own time", consumers.AvgMs)
	}
}

func TestTheCoroutineGaugeFollowsTheOpenConsumers(t *testing.T) {
	stats := newTestConsumerStats()

	stats.consumerOpened("ch1", "tag-1")
	stats.consumerOpened("ch1", "tag-2")

	if coroutines := stats.WorkloadSnapshot().Consumers.Coroutines; coroutines != 2 {
		t.Fatalf("coroutines = %d, want 2", coroutines)
	}

	stats.consumerClosed("ch1", "tag-1")

	if coroutines := stats.WorkloadSnapshot().Consumers.Coroutines; coroutines != 1 {
		t.Fatalf("coroutines = %d, want 1 after one ended", coroutines)
	}

	// Closing the same consumer twice — its own cleanup and the death of its channel
	// both get there — must not take the other one with it.
	stats.consumerClosed("ch1", "tag-1")

	if coroutines := stats.WorkloadSnapshot().Consumers.Coroutines; coroutines != 1 {
		t.Fatalf("coroutines = %d, want the second consumer to survive", coroutines)
	}
}

// Cancelling a consumer does not settle what it already took: the broker keeps those
// deliveries owed until they are acknowledged or the channel goes, so reporting them as
// in flight is the truth, not a leak. Closing the channel is what clears them.
func TestCancellingLeavesItsUnsettledDeliveriesInFlight(t *testing.T) {
	stats := newTestConsumerStats()

	stats.consumerOpened("ch1", "tag-1")
	stats.deliveryDispatched("ch1", 1, false)

	stats.consumerClosed("ch1", "tag-1")

	consumers := stats.WorkloadSnapshot().Consumers

	if consumers.Coroutines != 0 {
		t.Fatalf("coroutines = %d, want 0 after the cancel", consumers.Coroutines)
	}

	if consumers.InFlight != 1 {
		t.Fatalf("in flight = %d, want the unacknowledged delivery to still count", consumers.InFlight)
	}

	stats.channelGone("ch1")

	if inFlight := stats.WorkloadSnapshot().Consumers.InFlight; inFlight != 0 {
		t.Fatalf("in flight = %d, want the channel's death to clear it", inFlight)
	}
}

// A worker that has consumed nothing claims no workload at all, so a snapshot never
// carries a section it has no numbers for.
func TestAnIdleWorkerReportsNoConsumerSection(t *testing.T) {
	if section := newTestConsumerStats().WorkloadSnapshot().Consumers; section != nil {
		t.Fatalf("consumers = %#v, want no section", section)
	}
}
