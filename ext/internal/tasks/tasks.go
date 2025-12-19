package tasks

import (
	"sconcur/internal/dto"
	"sync"
	"sync/atomic"
)

type Tasks struct {
	mutex     sync.RWMutex
	active    map[string]*Task
	results   chan *dto.Result
	cancelled bool
	count     atomic.Int32
	closeOnce sync.Once
}
