package sleep_feature

import (
	"encoding/json"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/sleep/params"
	"sconcur/internal/helpers"
	"sconcur/internal/tasks"
	"sync"
	"time"
)

var _ contracts.FeatureContract = (*SleepFeature)(nil)

var once sync.Once
var instance *SleepFeature

var errFactory = errs.NewErrorsFactory("sleep")

type SleepFeature struct {
}

func Get() *SleepFeature {
	once.Do(func() {
		instance = &SleepFeature{}
	})

	return instance
}

func (s *SleepFeature) Handle(task *tasks.Task) {
	startTime := time.Now()
	message := task.GetMessage()

	var payload params.SleepPayload

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
			dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)),
		)
	}
}
