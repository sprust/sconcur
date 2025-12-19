package tasks

import (
	"sconcur/internal/dto"
	"sync"
	"sync/atomic"
)

type Tasks struct {
	mutex     sync.Mutex
	active    map[string]*Task
	results   chan *dto.Result
	cancelled bool
	count     atomic.Int32
	closeOnce sync.Once
}

func (t *Tasks) Results() chan *dto.Result {
	return t.results
}

func NewTasks() *Tasks {
	return &Tasks{
		active:  make(map[string]*Task),
		results: make(chan *dto.Result),
	}
}

func (t *Tasks) AddMessage(msg *dto.Message) *Task {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	task := NewTask(msg)

	t.active[msg.TaskKey] = task
	t.count.Add(1)

	return task
}

func (t *Tasks) AddResult(res *dto.Result) {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	if t.cancelled {
		return
	}

	exist, ok := t.active[res.TaskKey]

	if !ok {
		return
	}

	exist.res = res

	t.results <- res
}

func (t *Tasks) CancelTask(taskKey string) {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	task, ok := t.active[taskKey]

	if !ok {
		return
	}

	task.Cancel()

	delete(t.active, taskKey)
	t.count.Add(-1)
}

func (t *Tasks) Count() int {
	return int(t.count.Load())
}

func (t *Tasks) Cancel() {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	for _, task := range t.active {
		task.Cancel()
	}

	t.closeOnce.Do(func() {
		close(t.results)
	})

	t.active = make(map[string]*Task)
	t.count.Store(0)
	t.cancelled = true
}
