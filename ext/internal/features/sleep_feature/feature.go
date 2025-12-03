package sleep_feature

import (
	"context"
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

func (s *Feature) Handle(ctx context.Context, message *dto.Message) *dto.Result {
	var payload SleepPayload

	err := json.Unmarshal([]byte(message.Payload), &payload)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"parse error: %s",
				err.Error(),
			),
		}
	}

	select {
	case <-ctx.Done():
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: false,
			IsError:  true,
			Payload:  "closed by flow stop",
		}
	case <-time.After(time.Duration(payload.Milliseconds) * time.Millisecond):
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  false,
			Payload:  "",
		}
	}
}
