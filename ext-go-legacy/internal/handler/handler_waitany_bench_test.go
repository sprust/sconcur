package handler

import (
	"strconv"
	"testing"

	"sconcur/internal/dto"
	"sconcur/internal/types"
)

// Phase 0 of .ai/plans/php-go-boundary-batching.md: attribute today's cost of one
// result taken through waitAny. These benchmarks isolate the Go side only (no cgo,
// no PHP): the fast path where the buffered channel already holds results, the
// slow path where the consumer has to wait for a producer, and the batch drain.
//
// The results are fabricated and pushed straight into h.results, so no feature
// handler runs and no task is registered: deliver() still does its map lookup and
// counter work, which is what we want to measure, but the flow's task counter goes
// negative — harmless here, nothing reads it.

func benchResults(flowKey string, count int) []*dto.Result {
	results := make([]*dto.Result, count)

	for i := 0; i < count; i++ {
		results[i] = &dto.Result{
			FlowKey:     flowKey,
			Method:      types.MethodSleep,
			TaskKey:     flowKey + ":" + strconv.Itoa(i),
			Payload:     "{}",
			ExecutionMs: 1,
		}
	}

	return results
}

func benchHandlerWithFlow(flowKey string) *Handler {
	handler := NewHandler()

	handler.flows.InitFlow(handler.ctx, flowKey, handler.results)

	return handler
}

// BenchmarkWaitAnyBufferFull is the fast path: results are already sitting in the
// buffered channel, so WaitAny never parks. This is the per-result Go cost the
// scheduler pays under a dense fan-out — channel receive plus deliver().
func BenchmarkWaitAnyBufferFull(b *testing.B) {
	const flowKey = "bench-full"

	handler := benchHandlerWithFlow(flowKey)
	defer handler.Destroy()

	results := benchResults(flowKey, resultsBufferSize)

	b.ReportAllocs()
	b.ResetTimer()

	for consumed := 0; consumed < b.N; {
		b.StopTimer()

		batch := resultsBufferSize

		if remaining := b.N - consumed; remaining < batch {
			batch = remaining
		}

		for i := 0; i < batch; i++ {
			handler.results <- results[i]
		}

		b.StartTimer()

		for i := 0; i < batch; i++ {
			if _, err := handler.WaitAny(); err != nil {
				b.Fatal(err)
			}
		}

		consumed += batch
	}
}

// BenchmarkWaitAnyBufferEmpty is the slow path: the buffer is empty, so every
// result costs the spin-before-park (waitSpinIterations of runtime.Gosched) and,
// when that misses, a park plus a cross-goroutine wake-up. The number includes the
// rendezvous with the producer goroutine — that is inherent to "nothing is ready
// yet", which is exactly the state being measured.
func BenchmarkWaitAnyBufferEmpty(b *testing.B) {
	const flowKey = "bench-empty"

	handler := benchHandlerWithFlow(flowKey)
	defer handler.Destroy()

	results := benchResults(flowKey, 1)
	requests := make(chan struct{})

	go func() {
		for range requests {
			handler.results <- results[0]
		}
	}()

	defer close(requests)

	b.ReportAllocs()
	b.ResetTimer()

	for i := 0; i < b.N; i++ {
		requests <- struct{}{}

		if _, err := handler.WaitAny(); err != nil {
			b.Fatal(err)
		}
	}
}

// BenchmarkWaitAnyBatchBufferFull measures the same fast path through
// WaitAnyBatch: ns/op is per result, so the delta against
// BenchmarkWaitAnyBufferFull is what phase 1's batching saves on the Go side
// alone (the cgo crossing and the PHP-side userland call are measured separately).
func BenchmarkWaitAnyBatchBufferFull(b *testing.B) {
	const flowKey = "bench-batch"

	handler := benchHandlerWithFlow(flowKey)
	defer handler.Destroy()

	results := benchResults(flowKey, resultsBufferSize)

	b.ReportAllocs()
	b.ResetTimer()

	for consumed := 0; consumed < b.N; {
		b.StopTimer()

		batch := resultsBufferSize

		if remaining := b.N - consumed; remaining < batch {
			batch = remaining
		}

		for i := 0; i < batch; i++ {
			handler.results <- results[i]
		}

		b.StartTimer()

		for taken := 0; taken < batch; {
			drained, err := handler.WaitAnyBatch(64)

			if err != nil {
				b.Fatal(err)
			}

			taken += len(drained)
		}

		consumed += batch
	}
}
