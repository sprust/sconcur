package handler

import (
	"encoding/json"
	"sconcur/internal/dto"
	"sconcur/internal/features/sleep_feature/params"
	"testing"
)

func TestHandler_Sleep(t *testing.T) {
	h := NewHandler()
	defer h.Destroy()

	flowKey := "1"

	pl := params.SleepPayload{
		Milliseconds: 10,
	}

	psJs, err := json.Marshal(pl)

	if err != nil {
		t.Errorf("marshal error: %v", err)
	}

	msg := &dto.Message{
		FlowKey: flowKey,
		Method:  1,
		TaskKey: "1",
		Payload: string(psJs),
	}

	err = h.Push(msg)

	if err != nil {
		t.Errorf("unexpected error: %v", err)

		return
	}

	_, err = h.Wait(flowKey)

	if err == nil {
		t.Errorf("expected error, got nil")

		return
	}

	_, err = h.Wait(flowKey)

	if err == nil {
		t.Errorf("expected timeout error at -1 ms, got nil")

		return
	}

	_, err = h.Wait(flowKey)

	if err != nil {
		t.Errorf("unexpected error: %v", err)

		return
	}

	if h.GetTasksCount() != 0 {
		t.Errorf("expected tasks count to be 0, got %d", h.GetTasksCount())
	}
}
