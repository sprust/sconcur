package flows

import (
	"context"
	"errors"
	"fmt"
	"runtime/debug"
	"sconcur/internal/dto"
	"sconcur/internal/features"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sync"
	"sync/atomic"
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

	task := tasks.NewTask(f.ctx, f.results, msg)

	f.activeTasks[msg.TaskKey] = task
	f.tasksCount.Add(1)

	if msg.IsNext {
		go runTaskProtected(task, states.Get().Next)
	} else {
		handler, err := features.DetectMessageHandler(msg.Method)

		if err != nil {
			return err
		}

		go runTaskProtected(task, handler.Handle)
	}

	return nil
}

// runTaskProtected converts a panic into a task error result:
// an unrecovered panic in a c-shared library aborts the whole PHP process.
func runTaskProtected(task *tasks.Task, handle func(task *tasks.Task)) {
	defer func() {
		if recovered := recover(); recovered != nil {
			task.AddResult(
				dto.NewErrorResult(
					task.GetMessage(),
					fmt.Sprintf("panic: %v\n%s", recovered, debug.Stack()),
				),
			)
		}
	}()

	handle(task)
}

func (f *Flow) Wait() (*dto.Result, error) {
	select {
	case <-f.ctx.Done():
		return nil, f.ctx.Err()
	case result, ok := <-f.results:
		if !ok {
			return nil, errors.New("task channel closed")
		}

		f.mutex.Lock()

		delete(f.activeTasks, result.TaskKey)
		f.tasksCount.Add(-1)

		f.mutex.Unlock()

		return result, nil
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
