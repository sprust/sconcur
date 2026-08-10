package tasks

import (
	"context"
	"sconcur/internal/dto"
)

// Task carries one message through its feature handler. It holds the owning
// flow's context directly instead of deriving a per-task cancellable child: a
// task is never cancelled individually before its result is delivered (stopFlow
// cancels the whole flow), and the per-task context.WithCancel was a top
// per-request allocation site (the attribution plan, phase 5). State cleanup
// (states.Start's AfterFunc) rides the flow context the same way the derived
// child did — both fire on flow stop.
type Task struct {
	msg     *dto.Message
	flowCtx context.Context
	results chan *dto.Result
}

func NewTask(
	flowCtx context.Context,
	results chan *dto.Result,
	msg *dto.Message,
) *Task {
	return &Task{
		msg:     msg,
		flowCtx: flowCtx,
		results: results,
	}
}

func (t *Task) GetContext() context.Context {
	return t.flowCtx
}

func (t *Task) GetMessage() *dto.Message {
	return t.msg
}

func (t *Task) AddResult(result *dto.Result) {
	select {
	case t.results <- result:
	case <-t.flowCtx.Done():
	}
}
