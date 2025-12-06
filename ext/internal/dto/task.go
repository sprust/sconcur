package dto

import (
	"context"
	"sync"
)

type Task struct {
	msg       *Message
	res       *Result
	ctx       context.Context
	ctxCancel context.CancelFunc
	results   chan *Result
	mutex     sync.Mutex
	cancelled bool
}

func NewTask(msg *Message) *Task {
	ctx, cancel := context.WithCancel(context.Background())

	return &Task{
		msg:       msg,
		ctx:       ctx,
		ctxCancel: cancel,
		results:   make(chan *Result),
	}
}

func (t *Task) Ctx() context.Context {
	return t.ctx
}

func (t *Task) Msg() *Message {
	return t.msg
}

func (t *Task) AddResult(result *Result) {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	if t.cancelled {
		return
	}

	t.results <- result
}

func (t *Task) Results() chan *Result {
	return t.results
}

func (t *Task) Cancel() {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	if t.cancelled {
		return
	}

	t.ctxCancel()
	close(t.results)

	t.cancelled = true
}
