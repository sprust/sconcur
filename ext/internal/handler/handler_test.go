package handler

import (
	"errors"
	"strconv"
	"testing"
	"time"

	"sconcur/internal/dto"
	"sconcur/internal/types"

	"github.com/vmihailenco/msgpack/v5"
)

func sleepMessage(t *testing.T, flowKey, taskKey string, ms int64) *dto.Message {
	t.Helper()

	// The sleeper's payload key is "us" (microseconds, see SleeperPayload). With
	// a wrong key the value decodes as 0 and every task returns an instant
	// error, turning the cross-flow ordering assertions into a coin flip.
	payload, err := msgpack.Marshal(map[string]int64{"us": ms * 1000})

	if err != nil {
		t.Fatal(err)
	}

	return &dto.Message{
		FlowKey: flowKey,
		Method:  types.MethodSleep,
		TaskKey: taskKey,
		Payload: payload,
	}
}

// WaitAny must surface the first ready result of ANY flow — this is what lets
// flows progress concurrently instead of one flow blocking on its own channel.
func TestWaitAnyReturnsResultsAcrossFlowsAsReady(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	// Two separate flows; the faster task belongs to "flow-b".
	if err := h.Push(sleepMessage(t, "flow-a", "a-1", 60)); err != nil {
		t.Fatal(err)
	}
	if err := h.Push(sleepMessage(t, "flow-b", "b-1", 10)); err != nil {
		t.Fatal(err)
	}

	first, err := h.WaitAny()
	if err != nil {
		t.Fatal(err)
	}
	if first.FlowKey != "flow-b" || first.TaskKey != "b-1" {
		t.Fatalf("expected the faster flow-b result first, got %s/%s", first.FlowKey, first.TaskKey)
	}

	second, err := h.WaitAny()
	if err != nil {
		t.Fatal(err)
	}
	if second.FlowKey != "flow-a" || second.TaskKey != "a-1" {
		t.Fatalf("expected flow-a result second, got %s/%s", second.FlowKey, second.TaskKey)
	}

	if h.GetTasksCount() != 0 {
		t.Fatalf("expected zero active tasks after delivery, got %d", h.GetTasksCount())
	}
}

// Wait(flowKey) must return only the asked flow's result, buffering any other
// flow's result so a later Wait/WaitAny still sees it.
func TestWaitBuffersOtherFlowResults(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	if err := h.Push(sleepMessage(t, "fast", "fast-1", 1)); err != nil {
		t.Fatal(err)
	}
	if err := h.Push(sleepMessage(t, "slow", "slow-1", 80)); err != nil {
		t.Fatal(err)
	}

	// "fast" becomes ready first but we ask for "slow": fast must be buffered.
	slow, err := h.Wait("slow")
	if err != nil {
		t.Fatal(err)
	}
	if slow.FlowKey != "slow" {
		t.Fatalf("Wait(slow) returned %s", slow.FlowKey)
	}

	// The buffered "fast" result is still available.
	buffered, err := h.WaitAny()
	if err != nil {
		t.Fatal(err)
	}
	if buffered.FlowKey != "fast" || buffered.TaskKey != "fast-1" {
		t.Fatalf("expected buffered fast result, got %s/%s", buffered.FlowKey, buffered.TaskKey)
	}
}

// WaitAnyTimeout must return ErrWaitTimeout when nothing is ready in time, and a
// real result when one arrives before the deadline.
func TestWaitAnyTimeout(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	if _, err := h.WaitAnyTimeout(20); !errors.Is(err, ErrWaitTimeout) {
		t.Fatalf("expected ErrWaitTimeout on an idle handler, got %v", err)
	}

	if err := h.Push(sleepMessage(t, "flow", "task-1", 1)); err != nil {
		t.Fatal(err)
	}

	result, err := h.WaitAnyTimeout(1000)

	if err != nil {
		t.Fatalf("expected a result before the deadline, got %v", err)
	}

	if result.FlowKey != "flow" {
		t.Fatalf("expected flow result, got %s", result.FlowKey)
	}
}

func TestDestroyResetsHandler(t *testing.T) {
	h := NewHandler()

	if err := h.Push(sleepMessage(t, "flow", "task-1", 1)); err != nil {
		t.Fatal(err)
	}

	if _, err := h.WaitAny(); err != nil {
		t.Fatal(err)
	}

	h.Destroy()

	if h.GetTasksCount() != 0 {
		t.Fatalf("expected zero tasks after destroy, got %d", h.GetTasksCount())
	}

	// Handler is reusable after Destroy (fresh state).
	if err := h.Push(sleepMessage(t, "flow2", "task-2", 1)); err != nil {
		t.Fatal(err)
	}

	result, err := h.WaitAny()
	if err != nil {
		t.Fatal(err)
	}
	if result.FlowKey != "flow2" {
		t.Fatalf("expected flow2 result, got %s", result.FlowKey)
	}

	h.Destroy()
}

// The multiframe's count field is uint16: an unclamped max past 65535 would
// silently truncate the count and lose the excess (already-delivered) results.
func TestClampBatchMax(t *testing.T) {
	cases := map[int]int{
		-5:              1,
		0:               1,
		1:               1,
		64:              64,
		batchMaxCap:     batchMaxCap,
		batchMaxCap + 1: batchMaxCap,
		int(^uint32(0)): batchMaxCap,
	}

	for input, expected := range cases {
		if got := clampBatchMax(input); got != expected {
			t.Errorf("clampBatchMax(%d) = %d, expected %d", input, got, expected)
		}
	}
}

// A non-positive max must not lose the blocking first result: the batch is
// clamped to one result, and the rest stay drainable by the next call.
func TestWaitAnyBatchNonPositiveMaxStillShipsOneResult(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	if err := h.Push(sleepMessage(t, "clamp-a", "a-1", 1)); err != nil {
		t.Fatal(err)
	}
	if err := h.Push(sleepMessage(t, "clamp-b", "b-1", 1)); err != nil {
		t.Fatal(err)
	}

	first, err := h.WaitAnyBatch(0)
	if err != nil {
		t.Fatal(err)
	}
	if len(first) != 1 {
		t.Fatalf("expected exactly one result for max=0, got %d", len(first))
	}

	second, err := h.WaitAnyBatch(-3)
	if err != nil {
		t.Fatal(err)
	}
	if len(second) != 1 {
		t.Fatalf("expected the leftover result for max=-3, got %d", len(second))
	}

	if h.GetTasksCount() != 0 {
		t.Fatalf("expected zero tasks after draining, got %d", h.GetTasksCount())
	}
}

// After a wide batch, a narrow one must nil the buffer's stale tail: the slots
// past the new batch's length are the only remaining retainers of the previous
// batch's results (payloads included).
func TestDrainReadyClearsStaleBatchBufferTail(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	for i := 1; i <= 3; i++ {
		if err := h.Push(sleepMessage(t, "buf", "t-"+strconv.Itoa(i), 1)); err != nil {
			t.Fatal(err)
		}
	}

	// Let all three land in the buffered channel, then drain them in one batch.
	time.Sleep(50 * time.Millisecond)

	wide, err := h.WaitAnyBatch(64)
	if err != nil {
		t.Fatal(err)
	}
	if len(wide) != 3 {
		t.Fatalf("expected a batch of 3, got %d", len(wide))
	}

	if err := h.Push(sleepMessage(t, "buf", "t-4", 1)); err != nil {
		t.Fatal(err)
	}

	narrow, err := h.WaitAnyBatch(64)
	if err != nil {
		t.Fatal(err)
	}
	if len(narrow) != 1 {
		t.Fatalf("expected a batch of 1, got %d", len(narrow))
	}

	stale := h.batchBuffer[len(narrow):cap(h.batchBuffer)]

	for i, slot := range stale {
		if slot != nil {
			t.Fatalf("stale batchBuffer slot %d still retains a previous result", i)
		}
	}

	h.Destroy()

	if h.batchBuffer != nil {
		t.Fatal("Destroy must drop batchBuffer so the retained results are released")
	}
}
