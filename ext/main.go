package main

/*
#cgo CFLAGS: -D_GNU_SOURCE
*/
import "C"
import (
	"sconcur/internal/dto"
	handler2 "sconcur/internal/handler"
	"sconcur/internal/types"
)

var handler *handler2.Handler

func init() {
	handler = handler2.NewHandler()
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
func wait(fk *C.char) *C.char {
	res, err := handler.Wait(C.GoString(fk))

	if err != nil {
		return C.CString("error: " + err.Error())
	}

	return C.CString(res)
}

//export count
func count() int {
	return handler.GetTasksCount()
}

//export stopFlow
func stopFlow(fk *C.char) {
	handler.StopFlow(C.GoString(fk))
}

//export destroy
func destroy() {
	handler.Destroy()
}

//export version
func version() *C.char {
	return C.CString("0.0.1")
}

func main() {}
