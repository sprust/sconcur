package handler

import (
	"context"
	"errors"
	"sconcur/internal/dto"
	"sconcur/internal/features"
	"sconcur/internal/flows"
	"sync"
)

type Handler struct {
	ctx       context.Context
	ctxCancel context.CancelFunc
	mutex     sync.Mutex
	index     int64

	flows *flows.Flows

	// results is the single channel every flow's tasks publish into, so the PHP
	// side can wait for the first ready result of any flow (WaitAny). This is the
	// foundation for nested coroutines running concurrently with the outer flow.
	results chan *dto.Result

	// pending holds results already pulled from the channel (post-processed once
	// via deliver) but not yet claimed by a per-flow Wait. Transitional: it backs
	// the legacy Wait(flowKey) while the PHP side still uses per-flow waiting.
	// Only touched from Wait/WaitAny, which the single-threaded PHP caller
	// serializes. Remove once PHP moves fully to WaitAny.
	pending map[string][]*dto.Result
}

func NewHandler() *Handler {
	h := &Handler{}
	h.fresh()

	return h
}

func (h *Handler) Push(msg *dto.Message) error {
	flow := h.flows.InitFlow(h.ctx, msg.FlowKey, h.results)

	return flow.HandleMessage(msg)
}

// WaitAny returns the first ready result of any flow. It is the basis of the
// PHP-side scheduler: one global wait point that lets every flow progress
// concurrently instead of each flow blocking on its own channel.
func (h *Handler) WaitAny() (*dto.Result, error) {
	if result := h.popAnyPending(); result != nil {
		return result, nil
	}

	select {
	case <-h.ctx.Done():
		return nil, h.ctx.Err()
	case result, ok := <-h.results:
		if !ok {
			return nil, errors.New("results channel closed")
		}

		h.deliver(result)

		return result, nil
	}
}

// Wait returns the next result of a specific flow, buffering any other flow's
// results into pending. Transitional compatibility for the per-flow PHP/sync
// path; remove once PHP waits via WaitAny only.
func (h *Handler) Wait(flowKey string) (*dto.Result, error) {
	if result := h.popPending(flowKey); result != nil {
		return result, nil
	}

	for {
		select {
		case <-h.ctx.Done():
			return nil, h.ctx.Err()
		case result, ok := <-h.results:
			if !ok {
				return nil, errors.New("results channel closed")
			}

			h.deliver(result)

			if result.FlowKey == flowKey {
				return result, nil
			}

			h.pushPending(result)
		}
	}
}

// deliver applies the post-delivery bookkeeping (task accounting, context
// release) exactly once, when a result is first pulled from the shared channel.
func (h *Handler) deliver(result *dto.Result) {
	flow, err := h.flows.GetFlow(result.FlowKey)

	if err != nil {
		return
	}

	flow.OnDelivered(result)
}

func (h *Handler) popAnyPending() *dto.Result {
	h.mutex.Lock()
	defer h.mutex.Unlock()

	for flowKey, results := range h.pending {
		if len(results) == 0 {
			continue
		}

		result := results[0]
		h.pending[flowKey] = results[1:]

		if len(h.pending[flowKey]) == 0 {
			delete(h.pending, flowKey)
		}

		return result
	}

	return nil
}

func (h *Handler) popPending(flowKey string) *dto.Result {
	h.mutex.Lock()
	defer h.mutex.Unlock()

	results := h.pending[flowKey]

	if len(results) == 0 {
		return nil
	}

	result := results[0]
	h.pending[flowKey] = results[1:]

	if len(h.pending[flowKey]) == 0 {
		delete(h.pending, flowKey)
	}

	return result
}

func (h *Handler) pushPending(result *dto.Result) {
	h.mutex.Lock()
	defer h.mutex.Unlock()

	h.pending[result.FlowKey] = append(h.pending[result.FlowKey], result)
}

func (h *Handler) StopFlow(flowKey string) {
	h.flows.DeleteFlow(flowKey)
}

func (h *Handler) Destroy() {
	h.ctxCancel()
	h.flows.Cancel()
	features.Shutdown()
	h.fresh()
}

func (h *Handler) GetTasksCount() int {
	return h.flows.GetTasksCount()
}

func (h *Handler) fresh() {
	ctx, cancel := context.WithCancel(context.Background())

	h.ctx = ctx
	h.ctxCancel = cancel

	// Unbuffered: keeps the current backpressure — a finished task's goroutine
	// blocks in AddResult until the PHP side pulls the result (or its context is
	// cancelled).
	h.results = make(chan *dto.Result)
	h.pending = make(map[string][]*dto.Result)

	h.flows = flows.NewFlows()
}
