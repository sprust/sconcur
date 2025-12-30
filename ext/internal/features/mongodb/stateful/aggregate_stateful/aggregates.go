package aggregate_stateful

import (
	"context"
	"errors"
	"sync"
)

// TODO: make universal

var once sync.Once
var instance *AggregateStates

type AggregateStates struct {
	mutex  sync.RWMutex
	states map[string]*AggregateState
}

func GetAggregates() *AggregateStates {
	once.Do(func() {
		instance = &AggregateStates{
			states: make(map[string]*AggregateState),
		}
	})

	return instance
}

func (a *AggregateStates) AddState(ctx context.Context, taskKey string, state *AggregateState) error {
	a.mutex.Lock()
	defer a.mutex.Unlock()

	_, ok := a.states[taskKey]

	if ok {
		return errors.New("state already exists")
	}

	a.states[taskKey] = state

	go func() {
		<-ctx.Done()
		a.DeleteState(taskKey)
	}()

	return nil
}

func (a *AggregateStates) GetState(taskKey string) *AggregateState {
	a.mutex.RLock()
	defer a.mutex.RUnlock()

	state, ok := a.states[taskKey]

	if !ok {
		return nil
	}

	return state
}

func (a *AggregateStates) DeleteState(taskKey string) {
	a.mutex.Lock()
	defer a.mutex.Unlock()

	delete(a.states, taskKey)
}
