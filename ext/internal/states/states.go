package states

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

func (s *States) Start(ctx context.Context, taskKey string, state contracts.StateContract) (*dto.Result, error) {
	s.mutex.Lock()

	_, ok := s.states[taskKey]

	if ok {
		s.mutex.Unlock()

		return nil, errors.New("state already exists")
	}

	s.states[taskKey] = state

	s.mutex.Unlock()

	context.AfterFunc(ctx, func() {
		s.DeleteState(taskKey)
	})

	return s.handleNext(taskKey, state), nil
}

func (s *States) Next(task *tasks.Task) {
	message := task.GetMessage()

	state := s.GetState(message.TaskKey)

	if state == nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				"state not started",
			),
		)

		return
	}

	result := s.handleNext(message.TaskKey, state)

	task.AddResult(result)
}

func (s *States) GetState(taskKey string) contracts.StateContract {
	s.mutex.RLock()
	defer s.mutex.RUnlock()

	state, ok := s.states[taskKey]

	if !ok {
		return nil
	}

	return state
}

func (s *States) handleNext(taskKey string, state contracts.StateContract) *dto.Result {
	result := state.Next()

	if !result.HasNext {
		s.DeleteState(taskKey)
	}

	result.TaskKey = taskKey

	return result
}

func (s *States) DeleteState(taskKey string) {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	delete(s.states, taskKey)
}
