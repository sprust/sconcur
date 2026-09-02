package handler

import (
	"strconv"
	"testing"

	"sconcur/internal/dto"
	"sconcur/internal/types"

	"github.com/vmihailenco/msgpack/v5"
)

// benchSleepPayload is the sleeper's msgpack payload for ms=0 (return ASAP), so
// the benchmark measures the per-call machinery (one-shot Flow + channel +
// context + goroutine spawn + Message unmarshal + Result build), not a timer.
func benchSleepPayload(b *testing.B) []byte {
	b.Helper()

	payload, err := msgpack.Marshal(map[string]int64{"ms": 0})

	if err != nil {
		b.Fatal(err)
	}

	return payload
}

// BenchmarkRoundTripFreshFlow measures a full push -> waitAny round-trip where
// every call uses a brand-new flow key — the cost the sync path and each async
// coroutine's own flow pay: a one-shot Flow, its result channel and context are
// allocated and torn down per call. Phase 3 target (allocs/op).
func BenchmarkRoundTripFreshFlow(b *testing.B) {
	h := NewHandler()
	defer h.Destroy()

	payload := benchSleepPayload(b)

	b.ReportAllocs()
	b.ResetTimer()

	for i := 0; i < b.N; i++ {
		flowKey := "f-" + strconv.Itoa(i)

		msg := &dto.Message{
			FlowKey: flowKey,
			Method:  types.MethodSleep,
			TaskKey: flowKey + ":1",
			Payload: payload,
		}

		if err := h.Push(msg); err != nil {
			b.Fatal(err)
		}

		if _, err := h.WaitAny(); err != nil {
			b.Fatal(err)
		}

		h.StopFlow(flowKey)
	}
}

// BenchmarkRoundTripReusedFlow keeps one flow alive across all iterations, so the
// per-task cost is isolated from the one-shot Flow creation/teardown. The
// fresh-minus-reused delta is the allocation churn Phase 3's flow pooling / inline
// sync path would remove.
func BenchmarkRoundTripReusedFlow(b *testing.B) {
	h := NewHandler()
	defer h.Destroy()

	payload := benchSleepPayload(b)

	const flowKey = "reused"

	b.ReportAllocs()
	b.ResetTimer()

	for i := 0; i < b.N; i++ {
		msg := &dto.Message{
			FlowKey: flowKey,
			Method:  types.MethodSleep,
			TaskKey: flowKey + ":" + strconv.Itoa(i),
			Payload: payload,
		}

		if err := h.Push(msg); err != nil {
			b.Fatal(err)
		}

		if _, err := h.WaitAny(); err != nil {
			b.Fatal(err)
		}
	}

	b.StopTimer()

	h.StopFlow(flowKey)
}
