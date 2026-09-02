package handler

import (
	"strings"
	"testing"
	"time"

	"sconcur/internal/dto"
	"sconcur/internal/types"
)

// The detached (flowless) path runs its handler synchronously on the PHP thread,
// inside the push() cgo call — a handler that blocks there stalls the whole
// worker. Only allow-listed methods may take it, so a new feature cannot opt in
// by accident.
func TestPushRejectsANonDetachableMethod(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	err := h.Push(&dto.Message{
		Method:  types.MethodSleep,
		TaskKey: ":1",
		Payload: []byte("{}"),
	})

	if err == nil {
		t.Fatal("a detached sleep must be rejected: it would block the PHP thread inside push()")
	}

	if !strings.Contains(err.Error(), "detached") {
		t.Fatalf("expected the error to name the detached path, got %q", err)
	}
}

// next() addresses an existing stream on an existing flow, so it can never be
// detached.
func TestPushRejectsADetachedNext(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	err := h.Push(&dto.Message{
		Method:  types.MethodHttpRespond,
		TaskKey: ":1",
		IsNext:  true,
	})

	if err == nil {
		t.Fatal("a detached next must be rejected")
	}
}

// The allow-listed method goes through, creates no flow, and publishes no result
// PHP could ever claim: its result carries no flow key, so deliver() drops it.
// (The respond payload is deliberately unroutable here — the point is the push
// contract, not the write.)
func TestDetachedPushCreatesNoFlowAndNoDeliverableResult(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	if err := h.Push(&dto.Message{
		Method:  types.MethodHttpRespond,
		TaskKey: ":1",
		Payload: []byte("\x81\xa3rid\xa4nope"), // msgpack {"rid": "nope"}
	}); err != nil {
		t.Fatalf("detached push: %v", err)
	}

	if count := h.GetTasksCount(); count != 0 {
		t.Fatalf("a detached push must register no flow task, got %d", count)
	}

	if _, err := h.WaitAnyTimeout(50); err == nil {
		t.Fatal("a detached result must not be deliverable to PHP")
	}
}

// A detached handler must never park the PHP thread: the push has to return even
// though nothing on the other side consumes anything.
func TestDetachedPushReturnsWithoutBlocking(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	done := make(chan error, 1)

	go func() {
		done <- h.Push(&dto.Message{
			Method:  types.MethodHttpRespond,
			TaskKey: ":1",
			Payload: []byte("\x81\xa3rid\xa4nope"),
		})
	}()

	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("detached push: %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("the detached push blocked — on the PHP thread this is a frozen worker")
	}
}
