package main

/*
#cgo CFLAGS: -D_GNU_SOURCE

#include <stdlib.h>

typedef struct {
	void *data;
	int len;
	char *err;
} buffer_result_t;
*/
import "C"
import (
	"sconcur/internal/dto"
	handler2 "sconcur/internal/handler"
	"sconcur/internal/types"
	"unsafe"

	"github.com/vmihailenco/msgpack/v5"
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
func push(
	fk *C.char,
	fkLen C.int,
	mt C.int,
	tk *C.char,
	tkLen C.int,
	pl unsafe.Pointer,
	plLen C.int,
) *C.char {
	msg := &dto.Message{
		FlowKey: C.GoStringN(fk, fkLen),
		Method:  types.Method(mt),
		TaskKey: C.GoStringN(tk, tkLen),
		Payload: C.GoBytes(pl, plLen),
		IsNext:  false,
	}

	err := handler.Push(msg)

	if err != nil {
		return C.CString("error: push: " + err.Error())
	}

	return C.CString("")
}

//export next
func next(fk *C.char, tk *C.char) *C.char {
	msg := &dto.Message{
		FlowKey: C.GoString(fk),
		TaskKey: C.GoString(tk),
		IsNext:  true,
	}

	err := handler.Push(msg)

	if err != nil {
		return C.CString("error: next: " + err.Error())
	}

	return C.CString("")
}

//export wait
func wait(fk *C.char, fkLen C.int) C.buffer_result_t {
	res, err := handler.Wait(C.GoStringN(fk, fkLen))

	if err != nil {
		return C.buffer_result_t{
			data: nil,
			len:  0,
			err:  C.CString("error: " + err.Error()),
		}
	}

	serialized, err := msgpack.Marshal(res)

	if err != nil {
		return C.buffer_result_t{
			data: nil,
			len:  0,
			err:  C.CString("error: marshal msgpack: " + err.Error()),
		}
	}

	data := C.CBytes(serialized)

	return C.buffer_result_t{
		data: data,
		len:  C.int(len(serialized)),
		err:  nil,
	}
}

//export waitAny
func waitAny() C.buffer_result_t {
	res, err := handler.WaitAny()

	if err != nil {
		return C.buffer_result_t{
			data: nil,
			len:  0,
			err:  C.CString("error: " + err.Error()),
		}
	}

	serialized, err := msgpack.Marshal(res)

	if err != nil {
		return C.buffer_result_t{
			data: nil,
			len:  0,
			err:  C.CString("error: marshal msgpack: " + err.Error()),
		}
	}

	data := C.CBytes(serialized)

	return C.buffer_result_t{
		data: data,
		len:  C.int(len(serialized)),
		err:  nil,
	}
}

//export tasksCount
func tasksCount() int {
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
	return C.CString("0.1.0")
}

func main() {}
