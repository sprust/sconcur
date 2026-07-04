package handler

import (
	"context"
	"errors"
	"sconcur/internal/dto"
	"sconcur/internal/features"
	"sconcur/internal/flows"
	"sync"
	"time"
)

// ErrWaitTimeout is returned by WaitAnyTimeout when no result became ready within
// the deadline. It is not a failure: the caller polls again (e.g. the HTTP serve
// loop checks for a shutdown signal between waits).
var ErrWaitTimeout = errors.New("wait timeout")

// resultsBufferSize buffers the shared results channel so a finished task's
// goroutine publishes its result and exits instead of parking in AddResult until
// the PHP side pulls it: an unbuffered send into a blocking cgo receive is a
// rendezvous costing two futex wake-ups per result, which dominates the fan-out
// coordination price. Backpressure still applies past the buffer.
const resultsBufferSize = 1024

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

	for {
		select {
		case <-h.ctx.Done():
			return nil, h.ctx.Err()
		case result, ok := <-h.results:
			if !ok {
				return nil, errors.New("results channel closed")
			}

			if !h.deliver(result) {
				continue
			}

			return result, nil
		}
	}
}

// WaitAnyTimeout is WaitAny with a deadline: it returns ErrWaitTimeout if no
// result is ready within the given milliseconds, so a blocking PHP caller can
// wake periodically (e.g. to notice a shutdown signal on an idle server).
func (h *Handler) WaitAnyTimeout(ms int) (*dto.Result, error) {
	if result := h.popAnyPending(); result != nil {
		return result, nil
	}

	timer := time.NewTimer(time.Duration(ms) * time.Millisecond)
	defer timer.Stop()

	for {
		select {
		case <-h.ctx.Done():
			return nil, h.ctx.Err()
		case <-timer.C:
			return nil, ErrWaitTimeout
		case result, ok := <-h.results:
			if !ok {
				return nil, errors.New("results channel closed")
			}

			if !h.deliver(result) {
				continue
			}

			return result, nil
		}
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

			if !h.deliver(result) {
				continue
			}

			if result.FlowKey == flowKey {
				return result, nil
			}

			h.pushPending(result)
		}
	}
}

// deliver applies the post-delivery bookkeeping (task accounting, context
// release) exactly once, when a result is first pulled from the shared channel,
// and reports whether the result's flow is still known. A false return marks a
// stale result: with a buffered results channel a task may publish its result
// and the flow be stopped (StopFlow) before the PHP side pulls it — nobody
// waits for such a result anymore, so callers drop it and keep waiting.
func (h *Handler) deliver(result *dto.Result) bool {
	flow, err := h.flows.GetFlow(result.FlowKey)

	if err != nil {
		return false
	}

	flow.OnDelivered(result)

	return true
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

	// The stopped flow's results may still sit in pending (buffered there by a
	// per-flow Wait); nobody will ever claim them, so drop them with the flow.
	h.mutex.Lock()
	delete(h.pending, flowKey)
	h.mutex.Unlock()
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

	// Buffered (resultsBufferSize): ready results queue here so waitAny takes
	// the fast non-blocking receive path and finished goroutines exit without
	// parking. Past the buffer the old rendezvous backpressure applies — a
	// finished task's goroutine blocks in AddResult until the PHP side pulls a
	// result (or the task's context is cancelled).
	h.results = make(chan *dto.Result, resultsBufferSize)
	h.pending = make(map[string][]*dto.Result)

	h.flows = flows.NewFlows()
}
