package features

import (
	"encoding/json"
	"sconcur/internal/dto"
	"sconcur/internal/features/sleep_feature"
	"testing"
)

func TestHandler_Sleep(t *testing.T) {
	h := NewHandler()
	defer h.Stop()

	pl := sleep_feature.SleepPayload{
		Milliseconds: 10,
	}

	psJs, err := json.Marshal(pl)

	if err != nil {
		t.Errorf("marshal error: %v", err)
	}

	msg := &dto.Message{
		Method:  1,
		TaskKey: "1",
		Payload: string(psJs),
	}

	err = h.Push(msg)

	if err != nil {
		t.Errorf("unexpected error: %v", err)

		return
	}

	_, err = h.Wait(1)

	if err == nil {
		t.Errorf("expected error, got nil")

		return
	}

	_, err = h.Wait(10)

	if err != nil {
		t.Errorf("unexpected error: %v", err)

		return
	}
}
