package sleep_feature

import (
	"encoding/json"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/tasks"
	"time"
)

var _ contracts.MessageHandler = (*Feature)(nil)

var errFactory = errs.NewErrorsFactory("sleep")

type Feature struct {
}

func New() *Feature {
	return &Feature{}
}

func (s *Feature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var payload SleepPayload

	err := json.Unmarshal([]byte(message.Payload), &payload)

	if err != nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByErr("parse error", err),
			),
		)

		return
	}

	if payload.Milliseconds <= 0 {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByText("milliseconds must be greater than zero"),
			),
		)

		return
	}

	select {
	case <-task.GetContext().Done():
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByText("closed by task stop"),
			),
		)
	case <-time.After(time.Duration(payload.Milliseconds) * time.Millisecond):
		task.AddResult(
			dto.NewSuccessResult(message, ""),
		)
	}
}
