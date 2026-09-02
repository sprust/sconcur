package flows

import (
	"context"
	"errors"
	"sconcur/internal/dto"
	"sync"
)

type Flows struct {
	mutex sync.RWMutex
	flows map[string]*Flow

	// pool recycles Flow structs (and their activeTasks backing map) between a
	// DeleteFlow and the next InitFlow, so the common one-shot flow — the sync
	// path and every async coroutine's own flow — stops allocating a fresh Flow +
	// map on each call. Only the per-flow cancel context is still allocated (a
	// cancelled context cannot be reused). Safe because flow keys are never reused
	// (see Flow.reset). Per-Flows so it is dropped with the handler on Destroy.
	pool sync.Pool
}

func NewFlows() *Flows {
	return &Flows{
		flows: make(map[string]*Flow),
	}
}

func (f *Flows) InitFlow(handlerCtx context.Context, flowKey string, results chan *dto.Result) *Flow {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	flow, ok := f.flows[flowKey]

	if !ok {
		flow = f.acquire(handlerCtx, flowKey, results)

		f.flows[flowKey] = flow
	}

	return flow
}

// acquire returns a recycled Flow re-armed for this key, or a new one when the
// pool is empty. Called under the Flows lock.
func (f *Flows) acquire(handlerCtx context.Context, flowKey string, results chan *dto.Result) *Flow {
	pooled := f.pool.Get()

	if pooled == nil {
		return NewFlow(handlerCtx, flowKey, results)
	}

	flow := pooled.(*Flow)

	flow.reset(handlerCtx, flowKey, results)

	return flow
}

func (f *Flows) GetFlow(flowKey string) (*Flow, error) {
	f.mutex.RLock()
	defer f.mutex.RUnlock()

	flow, ok := f.flows[flowKey]

	if ok {
		return flow, nil
	}

	return nil, errors.New("flow not found")
}

func (f *Flows) DeleteFlow(flowKey string) {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	flow, ok := f.flows[flowKey]

	if !ok {
		return
	}

	flow.Cancel()
	delete(f.flows, flowKey)

	// Recycle the detached flow. Its key is retired for good (keys are never
	// reused), so any of its results still sitting in the buffered channel route
	// by a key GetFlow no longer knows and are dropped — they can never reach the
	// struct once it is re-armed for a new key.
	f.pool.Put(flow)
}

func (f *Flows) GetTasksCount() int {
	f.mutex.RLock()
	defer f.mutex.RUnlock()

	var count int

	for _, flow := range f.flows {
		count += flow.Count()
	}

	return count
}

func (f *Flows) Cancel() {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	for _, flow := range f.flows {
		flow.Cancel()
	}

	f.flows = make(map[string]*Flow)
}
