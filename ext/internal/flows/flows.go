package flows

import (
	"errors"
	"sync"
)

type Flows struct {
	mutex sync.Mutex
	flows map[string]*Flow
}

func NewFlows() *Flows {
	return &Flows{
		flows: make(map[string]*Flow),
	}
}

func (f *Flows) InitFlow(flowKey string) *Flow {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	flow, ok := f.flows[flowKey]

	if !ok {
		flow = NewFlow(flowKey)

		f.flows[flowKey] = flow
	}

	return flow
}

func (f *Flows) GetFlow(flowKey string) (*Flow, error) {
	f.mutex.Lock()
	defer f.mutex.Unlock()

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

	flow.GetTasks().Cancel()
	delete(f.flows, flowKey)
}

func (f *Flows) GetTasksCount() int {
	var count int

	for _, flow := range f.flows {
		count += flow.GetTasks().Count()
	}

	return count
}

func (f *Flows) Cancel() {
	for _, flow := range f.flows {
		flow.GetTasks().Cancel()
	}

	f.flows = make(map[string]*Flow)
}
