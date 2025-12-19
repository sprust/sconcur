package tasks

import (
	"context"
	"sconcur/internal/dto"
	"sync"
)

type Task struct {
	msg       *dto.Message
	res       *dto.Result
	ctx       context.Context
	ctxCancel context.CancelFunc
	results   chan *dto.Result
	mutex     sync.Mutex
	cancelled bool
}

func NewTask(
	flowCtx context.Context,
	results chan *dto.Result,
	msg *dto.Message,
) *Task {
	ctx, cancel := context.WithCancel(flowCtx)

	return &Task{
		msg:       msg,
		ctx:       ctx,
		ctxCancel: cancel,
		results:   results,
	}
}

func (t *Task) GetContext() context.Context {
	return t.ctx
}

func (t *Task) GetMessage() *dto.Message {
	return t.msg
}

func (t *Task) AddResult(result *dto.Result) {
	select {
	case t.results <- result:
	case <-t.ctx.Done():
	}
}
