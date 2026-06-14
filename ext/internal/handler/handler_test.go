package handler

import (
	"errors"
	"testing"

	"sconcur/internal/dto"
	"sconcur/internal/types"

	"github.com/vmihailenco/msgpack/v5"
)

func sleepMessage(t *testing.T, flowKey, taskKey string, ms int64) *dto.Message {
	t.Helper()

	payload, err := msgpack.Marshal(map[string]int64{"ms": ms})

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
