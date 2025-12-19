package flows

import (
	"context"
	"encoding/json"
	"errors"
	"sconcur/internal/dto"
	"sconcur/internal/features"
	"sconcur/internal/tasks"
	"sync"
	"sync/atomic"
	"time"
)

type Flow struct {
	mutex     sync.Mutex
	ctx       context.Context
	ctxCancel context.CancelFunc
	key       string

	activeTasks map[string]*tasks.Task
	tasksCount  atomic.Int32
	results     chan *dto.Result
}

func NewFlow(handlerCtx context.Context, key string) *Flow {
	ctx, ctxCancel := context.WithCancel(handlerCtx)

	return &Flow{
		ctx:         ctx,
		ctxCancel:   ctxCancel,
		key:         key,
		activeTasks: make(map[string]*tasks.Task),
		results:     make(chan *dto.Result),
	}
}

func (f *Flow) HandleMessage(msg *dto.Message) error {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	handler, err := features.DetectMessageHandler(msg.Method)

	if err != nil {
		return err
	}

	task := tasks.NewTask(f.ctx, f.results, msg)

	f.activeTasks[msg.TaskKey] = task
	f.tasksCount.Add(1)

	go handler.Handle(task)

	return nil
}

func (f *Flow) Wait(timeoutMs int64) (string, error) {
	if timeoutMs <= 0 {
		return "", errors.New("timeout must be greater than 0")
	}

	timer := time.NewTicker(time.Duration(timeoutMs) * time.Millisecond)
	defer timer.Stop()

	select {
	case <-f.ctx.Done():
		return "", f.ctx.Err()
	case <-timer.C:
		return "", errors.New("timeout waiting for task completion")
	case result, ok := <-f.results:
		if !ok {
			return "", errors.New("task channel closed")
		}

		f.mutex.Lock()

		if !result.HasNext {
			delete(f.activeTasks, result.TaskKey)
			f.tasksCount.Add(-1)
		}

		f.mutex.Unlock()

		b, err := json.Marshal(result)

		if err != nil {
			return "", err
		}

		return string(b), nil
	}
}

func (f *Flow) Count() int {
	return int(f.tasksCount.Load())
}

func (f *Flow) Cancel() {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	f.ctxCancel()
	f.tasksCount.Store(0)
}
