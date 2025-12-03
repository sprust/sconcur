package main

/*
#cgo CFLAGS: -D_GNU_SOURCE
*/
import "C"
import (
	"encoding/json"
	"sconcur/internal/dto"
	"sconcur/internal/features"
)

var handler *features.Handler

func init() {
	handler = features.NewHandler()
}

//export echo
func echo(str *C.char) *C.char {
	return C.CString("echo: " + C.GoString(str))
}

//export push
func push(pl *C.char) *C.char {
	var msg dto.Message

	err := json.Unmarshal([]byte(C.GoString(pl)), &msg)

	if err != nil {
		return C.CString("error: marshal msg: " + err.Error())
	}

	err = handler.Push(&msg)

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

//export stop
func stop() {
	handler.Stop()
}

func main() {}
