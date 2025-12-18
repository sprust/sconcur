package flows

import (
	"context"
	"sconcur/internal/tasks"
	"sync"
)

type Flow struct {
	mutex     sync.Mutex
	ctx       context.Context
	ctxCancel context.CancelFunc
	key       string
	tasks     *tasks.Tasks
}

func NewFlow(key string) *Flow {
	ctx, ctxCancel := context.WithCancel(context.Background())

	return &Flow{
		ctx:       ctx,
		ctxCancel: ctxCancel,
		key:       key,
		tasks:     tasks.NewTasks(),
	}
}

func (f *Flow) Ctx() context.Context {
	return f.ctx
}

func (f *Flow) Cancel() {
	f.ctxCancel()
}

func (f *Flow) GetTasks() *tasks.Tasks {
	return f.tasks
}
