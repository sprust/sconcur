package flows

import (
	"context"
	"errors"
	"sync"
)

type Flows struct {
	mutex sync.RWMutex
	flows map[string]*Flow
}

func NewFlows() *Flows {
	return &Flows{
		flows: make(map[string]*Flow),
	}
}

func (f *Flows) InitFlow(handlerCtx context.Context, flowKey string) *Flow {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	flow, ok := f.flows[flowKey]

	if !ok {
		flow = NewFlow(handlerCtx, flowKey)

		f.flows[flowKey] = flow
	}

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
