package httpserver_feature

import (
	"sconcur/internal/stats"
	"sync"
	"time"
)

// requestStats is the HTTP server's workload counters: completed requests, their
// total duration (for the running average), and the in-flight set keyed by request
// id (with each request's start, for the exclusive age buckets). It implements
// stats.WorkloadProvider, so the shared Collector folds it into each snapshot.
type requestStats struct {
	// completedMutex guards the (completed, totalDurationMicros) pair: a snapshot
	// must read both together to compute a consistent average. Two independent
	// atomics could be read between a completed++ and its duration add, skewing
	// avgMs. The critical section is two integer adds, so contention between
	// concurrent request completions is negligible. The in-flight set stays
	// lock-free (sync.Map).
	completedMutex      sync.Mutex
	completed           int64
	totalDurationMicros int64

	inFlight sync.Map
}

// requestBegan records a request entering handling, keyed by its id for the
// in-flight age buckets.
func (requestStats *requestStats) requestBegan(requestId string, start time.Time) {
	requestStats.inFlight.Store(requestId, start)
}

// requestEnded records a finished request: drop it from the in-flight set and add
// its duration to the completed counters.
func (requestStats *requestStats) requestEnded(requestId string, start time.Time) {
	requestStats.inFlight.Delete(requestId)

	requestStats.completedMutex.Lock()
	requestStats.completed++
	requestStats.totalDurationMicros += time.Since(start).Microseconds()
	requestStats.completedMutex.Unlock()
}

// WorkloadSnapshot reads the counters and buckets the in-flight requests by age
// (exclusive buckets).
func (requestStats *requestStats) WorkloadSnapshot() stats.Workload {
	now := time.Now()

	requestStats.completedMutex.Lock()
	completed := requestStats.completed
	totalMicros := requestStats.totalDurationMicros
	requestStats.completedMutex.Unlock()

	averageMs := 0.0

	if completed > 0 {
		averageMs = float64(totalMicros) / float64(completed) / 1000.0
	}

	requests := &stats.Requests{
		Completed: completed,
		AvgMs:     averageMs,
	}

	requestStats.inFlight.Range(func(_ any, value any) bool {
		start, ok := value.(time.Time)

		if !ok {
			return true
		}

		age := now.Sub(start)

		requests.InFlight++

		switch {
		case age >= 15*time.Second:
			requests.InFlightOver15s++
		case age >= 5*time.Second:
			requests.InFlight5to15s++
		case age >= 1*time.Second:
			requests.InFlight1to5s++
		}

		return true
	})

	return stats.Workload{Requests: requests}
}
