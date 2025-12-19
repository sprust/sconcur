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
	"sconcur/internal/flows"
	"sconcur/internal/types"
	"sync"
	"time"
)

type Handler struct {
	ctx       context.Context
	ctxCancel context.CancelFunc
	mutex     sync.Mutex
	index     int64

	flows *flows.Flows
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

	flow := h.flows.InitFlow(msg.FlowKey)

	taskGroup := flow.GetTasks()
	task := taskGroup.AddMessage(msg)

	go func() {
		defer taskGroup.CancelTask(msg.TaskKey)

		go handler.Handle(task)

		for {
			select {
			case <-h.ctx.Done(): // destroy
				return
			case <-flow.Ctx().Done(): // cancel flow
				return
			case <-task.Ctx().Done(): // cancel task
				return
			case result, ok := <-task.Results():
				if ok {
					taskGroup.AddResult(result)

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

func (h *Handler) Wait(flowKey string, timeoutMs int64) (string, error) {
	if timeoutMs <= 0 {
		return "", errors.New("timeout must be greater than 0")
	}

	timer := time.NewTicker(time.Duration(timeoutMs) * time.Millisecond)
	defer timer.Stop()

	flow, err := h.flows.GetFlow(flowKey)

	if err != nil {
		return "", err
	}

	results := flow.GetTasks().Results()

	select {
	case <-h.ctx.Done(): // destroy
		return "", h.ctx.Err()
	case <-flow.Ctx().Done(): // cancel flow
		return "", flow.Ctx().Err()
	case <-timer.C: // timeout
		return "", errors.New("timeout waiting for task completion")
	case res, ok := <-results:
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

func (h *Handler) CancelTask(flowKey string, taskKey string) {
	go func() {
		flow, err := h.flows.GetFlow(flowKey)

		if err != nil {
			return
		}

		flow.GetTasks().CancelTask(taskKey)
	}()
}

func (h *Handler) StopFlow(flowKey string) {
	go h.flows.DeleteFlow(flowKey)
}

func (h *Handler) Destroy() {
	h.ctxCancel()
	h.flows.Cancel()
	h.fresh()
}

func (h *Handler) GetTasksCount() int {
	return h.flows.GetTasksCount()
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

	h.flows = flows.NewFlows()
}
