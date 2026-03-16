package stateful

import (
	"context"
	"errors"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/tasks"
	"sync"
)

var once sync.Once
var instance *States

type States struct {
	mutex  sync.RWMutex
	states map[string]contracts.StateContract
}

func Get() *States {
	once.Do(func() {
		instance = &States{
			states: make(map[string]contracts.StateContract),
		}
	})

	return instance
}

func (a *States) Start(ctx context.Context, taskKey string, state contracts.StateContract) (*dto.Result, error) {
	a.mutex.Lock()

	_, ok := a.states[taskKey]

	if ok {
		a.mutex.Unlock()

		return nil, errors.New("state already exists")
	}

	a.states[taskKey] = state

	a.mutex.Unlock()

	context.AfterFunc(ctx, func() {
		a.DeleteState(taskKey)
	})

	return a.handleNext(taskKey, state), nil
}

func (a *States) Next(task *tasks.Task) {
	message := task.GetMessage()

	state := a.GetState(message.TaskKey)

	if state == nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				"state not started",
			),
		)

		return
	}

	result := a.handleNext(message.TaskKey, state)

	task.AddResult(result)
}

func (a *States) GetState(taskKey string) contracts.StateContract {
	a.mutex.RLock()
	defer a.mutex.RUnlock()

	state, ok := a.states[taskKey]

	if !ok {
		return nil
	}

	return state
}

func (a *States) handleNext(taskKey string, state contracts.StateContract) *dto.Result {
	result := state.Next()

	if !result.HasNext {
		a.DeleteState(taskKey)
	}

	result.TaskKey = taskKey

	return result
}

func (a *States) DeleteState(taskKey string) {
	a.mutex.Lock()
	defer a.mutex.Unlock()

	delete(a.states, taskKey)
}
