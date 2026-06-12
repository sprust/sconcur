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

	// Resolve the handler before mutating flow state: a task registered for
	// a message that will never run would corrupt the tasks accounting and
	// leave PHP waiting forever.
	var handle func(task *tasks.Task)

	if msg.IsNext {
		handle = states.Get().Next
	} else {
		handler, err := features.DetectMessageHandler(msg.Method)

		if err != nil {
			return err
		}

		handle = handler.Handle
	}

	task := tasks.NewTask(f.ctx, f.results, msg)

	f.activeTasks[msg.TaskKey] = task
	f.tasksCount.Add(1)

	go runTaskProtected(task, handle)

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

		task := f.activeTasks[result.TaskKey]

		delete(f.activeTasks, result.TaskKey)
		f.tasksCount.Add(-1)

		f.mutex.Unlock()

		// Release the task context once its result is delivered. The initial
		// task of a multi-batch find/aggregate is the exception: its context
		// owns the cursor state lifetime (states.Start hooks AfterFunc on it),
		// so it must live until the state is finished or the flow is stopped.
		if task != nil && (task.GetMessage().IsNext || !result.HasNext) {
			task.Cancel()
		}

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
