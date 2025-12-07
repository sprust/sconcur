package flows

import (
	"sconcur/internal/tasks"
	"sync"
)

type Flow struct {
	mutex sync.Mutex
	key   string
	tasks *tasks.Tasks
}

func NewFlow(key string) *Flow {
	return &Flow{
		key:   key,
		tasks: tasks.NewTasks(),
	}
}

func (f *Flow) GetTasks() *tasks.Tasks {
	return f.tasks
}
