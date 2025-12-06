package sleep_feature

import (
	"encoding/json"
	"fmt"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"time"
)

var _ contracts.MessageHandler = (*Feature)(nil)

type Feature struct {
}

func New() *Feature {
	return &Feature{}
}

func (s *Feature) Handle(task *dto.Task) {
	message := task.Msg()

	var payload SleepPayload

	err := json.Unmarshal([]byte(message.Payload), &payload)

	if err != nil {
		task.AddResult(
			&dto.Result{
				Method:   message.Method,
				TaskKey:  message.TaskKey,
				Waitable: true,
				IsError:  true,
				Payload: fmt.Sprintf(
					"parse error: %s",
					err.Error(),
				),
			},
		)

		return
	}

	select {
	case <-task.Ctx().Done():
		task.AddResult(
			&dto.Result{
				Method:   message.Method,
				TaskKey:  message.TaskKey,
				Waitable: false,
				IsError:  true,
				Payload:  "closed by task stop",
			},
		)
	case <-time.After(time.Duration(payload.Milliseconds) * time.Millisecond):
		task.AddResult(
			&dto.Result{
				Method:   message.Method,
				TaskKey:  message.TaskKey,
				Waitable: true,
				IsError:  false,
				Payload:  "",
			},
		)
	}
}
