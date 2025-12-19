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

func NewTask(msg *dto.Message) *Task {
	ctx, cancel := context.WithCancel(context.Background())

	return &Task{
		msg:       msg,
		ctx:       ctx,
		ctxCancel: cancel,
		results:   make(chan *dto.Result),
	}
}

func (t *Task) Ctx() context.Context {
	return t.ctx
}

func (t *Task) Msg() *dto.Message {
	return t.msg
}

func (t *Task) AddResult(result *dto.Result) {
	t.mutex.Lock()

	if t.cancelled {
		t.mutex.Unlock()

		return
	}

	t.mutex.Unlock()

	t.results <- result
}

func (t *Task) Results() chan *dto.Result {
	return t.results
}

func (t *Task) Cancel() {
	t.mutex.Lock()

	if t.cancelled {
		t.mutex.Unlock()

		return
	}

	t.ctxCancel()
	close(t.results)

	t.cancelled = true

	t.mutex.Unlock()
}
