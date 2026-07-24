package flows

import (
	"context"
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

// NewFlow builds a flow that publishes task results into the shared results
// channel owned by the handler. All flows write to the same channel so the PHP
// side can wait for any flow's result at once (waitAny), which is what lets
// nested coroutines run concurrently with the outer flow.
func NewFlow(handlerCtx context.Context, key string, results chan *dto.Result) *Flow {
	ctx, ctxCancel := context.WithCancel(handlerCtx)

	return &Flow{
		ctx:         ctx,
		ctxCancel:   ctxCancel,
		key:         key,
		activeTasks: make(map[string]*tasks.Task),
		results:     results,
	}
}

// reset re-arms a pooled Flow for a new flow key, reusing the struct and its
// activeTasks backing map (cleared, not reallocated). Flow keys are globally
// unique and never reused (uniqid / monotonic sp_N counters), so a struct
// reused under a new key is invisible to any stale result of the old key: that
// result routes by its own string key, which GetFlow no longer knows, so it is
// dropped before ever reaching this flow. A fresh cancel context is derived —
// a cancelled context cannot be reused. Called only from Flows.InitFlow, which
// holds the Flows lock and only pools flows already detached from the registry.
func (f *Flow) reset(handlerCtx context.Context, key string, results chan *dto.Result) {
	ctx, ctxCancel := context.WithCancel(handlerCtx)

	f.ctx = ctx
	f.ctxCancel = ctxCancel
	f.key = key
	f.results = results

	clear(f.activeTasks)
	f.tasksCount.Store(0)
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

// OnDelivered runs the post-delivery bookkeeping for a result that has just
// been pulled from the shared channel by the handler: drop the task from the
// active set, decrement the counter and release the task context.
//
// The initial task of a multi-batch find/aggregate is the exception: its
// context owns the cursor state lifetime (states.Start hooks AfterFunc on it),
// so it must live until the state is finished or the flow is stopped.
func (f *Flow) OnDelivered(result *dto.Result) {
	f.mutex.Lock()

	task := f.activeTasks[result.TaskKey]

	delete(f.activeTasks, result.TaskKey)
	f.tasksCount.Add(-1)

	f.mutex.Unlock()

	if task != nil && (task.GetMessage().IsNext || !result.HasNext) {
		task.Cancel()
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
