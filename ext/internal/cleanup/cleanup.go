package cleanup

import "sync"

type Func func(flowKey string)

var (
	mutex sync.RWMutex
	funcs []Func
)

func Register(fn Func) {
	mutex.Lock()
	defer mutex.Unlock()

	funcs = append(funcs, fn)
}

func Run(flowKey string) {
	mutex.RLock()
	defer mutex.RUnlock()

	for _, fn := range funcs {
		fn(flowKey)
	}
}
