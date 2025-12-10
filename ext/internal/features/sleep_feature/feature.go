package sleep_feature

import (
	"encoding/json"
	"fmt"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/tasks"
	"time"
)

var _ contracts.MessageHandler = (*Feature)(nil)

type Feature struct {
}

func New() *Feature {
	return &Feature{}
}

func (s *Feature) Handle(task *tasks.Task) {
	message := task.Msg()

	var payload SleepPayload

	err := json.Unmarshal([]byte(message.Payload), &payload)

	if err != nil {
		task.AddResult(
			dto.NewErrorResult(message, fmt.Sprintf(
				"parse error: %s",
				err.Error(),
			)),
		)

		return
	}

	select {
	case <-task.Ctx().Done():
		task.AddResult(
			dto.NewErrorResult(message, "closed by task stop"),
		)
	case <-time.After(time.Duration(payload.Milliseconds) * time.Millisecond):
		task.AddResult(
			dto.NewSuccessResult(message, ""),
		)
	}
}
