package handler

import (
	"context"
	"sconcur/internal/dto"
	"sconcur/internal/flows"
	"sync"
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
	flow := h.flows.InitFlow(h.ctx, msg.FlowKey)

	return flow.HandleMessage(msg)
}

func (h *Handler) Wait(flowKey string) (string, error) {
	flow, err := h.flows.GetFlow(flowKey)

	if err != nil {
		return "", err
	}

	return flow.Wait()
}

func (h *Handler) StopFlow(flowKey string) {
	h.flows.DeleteFlow(flowKey)
}

func (h *Handler) Destroy() {
	h.ctxCancel()
	h.flows.Cancel()
	h.fresh()
}

func (h *Handler) GetTasksCount() int {
	return h.flows.GetTasksCount()
}

func (h *Handler) fresh() {
	ctx, cancel := context.WithCancel(context.Background())

	h.ctx = ctx
	h.ctxCancel = cancel

	h.flows = flows.NewFlows()
}
