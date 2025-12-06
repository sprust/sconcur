package features

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/features/mongodb_feature"
	"sconcur/internal/features/mongodb_feature/connections"
	"sconcur/internal/features/sleep_feature"
	"sconcur/internal/types"
	"sync"
	"time"
)

type Handler struct {
	ctx       context.Context
	ctxCancel context.CancelFunc
	mutex     sync.Mutex
	index     int64

	tasks *dto.Tasks
}

func NewHandler() *Handler {
	h := &Handler{}
	h.fresh()

	return h
}

func (h *Handler) Push(msg *dto.Message) error {
	handler, err := h.detectHandler(msg.Method)

	if err != nil {
		return err
	}

	go func() {
		task := h.tasks.AddMessage(msg)
		defer h.tasks.StopTask(msg.TaskKey)

		go handler.Handle(task)

		for {
			select {
			case <-h.ctx.Done():
				return
			case <-task.Ctx().Done():
				return
			case result, ok := <-task.Results():
				if ok {
					h.tasks.AddResult(result)

					if result.HasNext {
						continue
					}
				}

				return
			}
		}
	}()

	return nil
}

func (h *Handler) Wait(timeoutMs int64) (string, error) {
	if timeoutMs <= 0 {
		return "", errors.New("timeout waiting for task completion")
	}

	timer := time.NewTimer(time.Duration(timeoutMs) * time.Millisecond)
	defer timer.Stop()

	select {
	case <-h.ctx.Done():
		return "", h.ctx.Err()
	case <-timer.C:
		return "", errors.New("timeout waiting for task completion")
	case res, ok := <-h.tasks.Results():
		if !ok {
			return "", errors.New("task channel closed")
		}

		b, err := json.Marshal(res)

		if err != nil {
			return "", err
		}

		return string(b), nil
	}
}

func (h *Handler) StopTask(taskKey string) {
	go h.tasks.StopTask(taskKey)
}

func (h *Handler) Stop() {
	h.ctxCancel()
	h.tasks.Cancel()
	h.fresh()
}

func (h *Handler) GetTasksCount() int {
	return h.tasks.Count()
}

func (h *Handler) detectHandler(method types.Method) (contracts.MessageHandler, error) {
	if method == 1 {
		return sleep_feature.New(), nil
	}

	if method == 2 {
		return mongodb_feature.New(
			connections.GetConnections(),
		), nil
	}

	return nil, errors.New("unknown method: " + fmt.Sprint(method))
}

func (h *Handler) fresh() {
	ctx, cancel := context.WithCancel(context.Background())

	h.ctx = ctx
	h.ctxCancel = cancel

	h.tasks = dto.NewTasks()
}
