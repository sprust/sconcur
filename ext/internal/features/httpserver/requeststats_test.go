package httpserver_feature

import (
	"strconv"
	"sync"
	"testing"
	"time"
)

// TestRequestStatsBucketsAndCounters checks the in-flight age bucketing (exclusive
// buckets) and the completed counter: an ended request leaves the in-flight set and
// bumps completed; live requests fall into the right age bucket by their start time.
func TestRequestStatsBucketsAndCounters(t *testing.T) {
	requestStats := &requestStats{}

	now := time.Now()

	requestStats.requestBegan("fresh", now) // age ~0 → in-flight only
	requestStats.requestBegan("aged1to5", now.Add(-3*time.Second))
	requestStats.requestBegan("aged5to15", now.Add(-9*time.Second))
	requestStats.requestBegan("agedOver15", now.Add(-20*time.Second))

	requestStats.requestBegan("done", now)
	requestStats.requestEnded("done", now) // leaves in-flight, completed = 1

	workload := requestStats.WorkloadSnapshot()
	requests := workload.Requests

	if requests == nil {
		t.Fatal("WorkloadSnapshot returned no requests section")
	}

	if requests.Completed != 1 {
		t.Errorf("Completed = %d, want 1", requests.Completed)
	}

	if requests.InFlight != 4 {
		t.Errorf("InFlight = %d, want 4 (the ended one is gone)", requests.InFlight)
	}

	if requests.InFlight1to5s != 1 {
		t.Errorf("InFlight1to5s = %d, want 1", requests.InFlight1to5s)
	}

	if requests.InFlight5to15s != 1 {
		t.Errorf("InFlight5to15s = %d, want 1", requests.InFlight5to15s)
	}

	if requests.InFlightOver15s != 1 {
		t.Errorf("InFlightOver15s = %d, want 1", requests.InFlightOver15s)
	}

	if requests.AvgMs < 0 {
		t.Errorf("AvgMs = %v, want >= 0", requests.AvgMs)
	}
}

// TestRequestStatsAverageZeroWhenNoneCompleted guards the divide-by-zero edge: with
// no completed requests the average is 0, not NaN.
func TestRequestStatsAverageZeroWhenNoneCompleted(t *testing.T) {
	requestStats := &requestStats{}

	workload := requestStats.WorkloadSnapshot()

	if workload.Requests.AvgMs != 0 {
		t.Errorf("AvgMs = %v, want 0 when nothing completed", workload.Requests.AvgMs)
	}

	if workload.Requests.Completed != 0 {
		t.Errorf("Completed = %d, want 0", workload.Requests.Completed)
	}
}

// TestRequestStatsConcurrentCompletions hammers requestEnded from many goroutines
// (as net/http does, one per request) and checks the counters stay consistent:
// every completion is counted and the in-flight set drains. Run with -race to also
// catch a regression in the (completed, totalDurationMicros) pair locking.
func TestRequestStatsConcurrentCompletions(t *testing.T) {
	requestStats := &requestStats{}

	start := time.Now()

	const total = 200

	var waitGroup sync.WaitGroup

	waitGroup.Add(total)

	for index := 0; index < total; index++ {
		go func(index int) {
			defer waitGroup.Done()

			requestId := strconv.Itoa(index)

			requestStats.requestBegan(requestId, start)
			requestStats.requestEnded(requestId, start)
		}(index)
	}

	waitGroup.Wait()

	workload := requestStats.WorkloadSnapshot()

	if workload.Requests.Completed != total {
		t.Errorf("Completed = %d, want %d", workload.Requests.Completed, total)
	}

	if workload.Requests.InFlight != 0 {
		t.Errorf("InFlight = %d, want 0", workload.Requests.InFlight)
	}
}
