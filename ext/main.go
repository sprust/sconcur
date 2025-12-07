package main

/*
#cgo CFLAGS: -D_GNU_SOURCE
*/
import "C"
import (
	"sconcur/internal/dto"
	"sconcur/internal/features"
	"sconcur/internal/types"
)

var handler *features.Handler

func init() {
	handler = features.NewHandler()
}

//export ping
func ping(str *C.char) *C.char {
	return C.CString("ping: " + C.GoString(str))
}

//export push
func push(fk *C.char, mt int, tk *C.char, pl *C.char) *C.char {
	msg := &dto.Message{
		FlowKey: C.GoString(fk),
		Method:  types.Method(mt),
		TaskKey: C.GoString(tk),
		Payload: C.GoString(pl),
	}

	err := handler.Push(msg)

	if err != nil {
		return C.CString("error: push: " + err.Error())
	}

	return C.CString("")
}

//export wait
func wait(fk *C.char, ms int64) *C.char {
	res, err := handler.Wait(C.GoString(fk), ms)

	if err != nil {
		return C.CString("error: " + err.Error())
	}

	return C.CString(res)
}

//export count
func count() int {
	return handler.GetTasksCount()
}

//export cancel
func cancel(fk *C.char, tk *C.char) {
	handler.StopTask(C.GoString(fk), C.GoString(tk))
}

//export stop
func stop() {
	handler.Stop()
}

func main() {}
