package dto

import (
	"sync"
)

type Tasks struct {
	mutex   sync.Mutex
	active  map[string]*Task
	results chan *Result
}

func (t *Tasks) Results() chan *Result {
	return t.results
}

func NewTasks() *Tasks {
	return &Tasks{
		active:  make(map[string]*Task),
		results: make(chan *Result),
	}
}

func (t *Tasks) AddMessage(msg *Message) *Task {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	task := NewTask(msg)

	t.active[msg.TaskKey] = task

	return task
}

func (t *Tasks) AddResult(res *Result) {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	exist, ok := t.active[res.TaskKey]

	if !ok {
		return
	}

	exist.res = res

	t.results <- res
}

func (t *Tasks) StopTask(taskKey string) {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	exist, ok := t.active[taskKey]

	if !ok {
		return
	}

	delete(t.active, taskKey)

	exist.Cancel()
}

func (t *Tasks) Count() int {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	return len(t.active)
}

func (t *Tasks) Cancel() {
	t.mutex.Lock()
	defer t.mutex.Unlock()

	for _, task := range t.active {
		task.Cancel()
	}

	close(t.results)
}
