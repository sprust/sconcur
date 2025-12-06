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
func push(mt int, tk *C.char, pl *C.char) *C.char {
	msg := &dto.Message{
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
func wait(ms int64) *C.char {
	res, err := handler.Wait(ms)

	if err != nil {
		return C.CString("error: " + err.Error())
	}

	return C.CString(res)
}

//export cancel
func cancel(tk *C.char) {
	handler.StopTask(C.GoString(tk))
}

//export stop
func stop() {
	handler.Stop()
}

func main() {}
