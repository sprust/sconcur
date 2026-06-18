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
	"encoding/binary"
	"errors"
	"sconcur/internal/dto"
	httpserver_feature "sconcur/internal/features/httpserver"
	handler2 "sconcur/internal/handler"
	"sconcur/internal/logger"
	"sconcur/internal/types"
	"unsafe"
)

// Result frame layout (Go -> PHP). The envelope is a fixed binary header, not
// MessagePack, so the result is never double-encoded: only the feature payload
// stays MessagePack and is decoded once on the PHP side. Mirrors how push passes
// its envelope as separate arguments. Must match Extension::parseWaitResponse.
//
//	[0]      flags    uint8  (bit0 isError, bit1 hasNext)
//	[1]      method   uint8
//	[2:6]    execMs   uint32 (big-endian)
//	[6:8]    flowKey  length uint16 (big-endian)
//	[8:10]   taskKey  length uint16 (big-endian)
//	[10:]    flowKey bytes, then taskKey bytes, then the raw payload (the rest)
const (
	frameHeaderSize  = 10
	frameFlagError   = 1 << 0
	frameFlagHasNext = 1 << 1
)

// buildResultFrame serializes a result envelope as the fixed binary header followed
// by the raw (already-encoded) payload bytes.
func buildResultFrame(result *dto.Result) []byte {
	flowKey := []byte(result.FlowKey)
	taskKey := []byte(result.TaskKey)
	payload := []byte(result.Payload)

	frame := make([]byte, frameHeaderSize+len(flowKey)+len(taskKey)+len(payload))

	var flags byte

	if result.IsError {
		flags |= frameFlagError
	}

	if result.HasNext {
		flags |= frameFlagHasNext
	}

	frame[0] = flags
	frame[1] = byte(result.Method)
	binary.BigEndian.PutUint32(frame[2:6], uint32(result.ExecutionMs))
	binary.BigEndian.PutUint16(frame[6:8], uint16(len(flowKey)))
	binary.BigEndian.PutUint16(frame[8:10], uint16(len(taskKey)))

	offset := frameHeaderSize
	offset += copy(frame[offset:], flowKey)
	offset += copy(frame[offset:], taskKey)
	copy(frame[offset:], payload)

	return frame
}

// frameResult builds the buffer_result_t carrying a framed result.
func frameResult(result *dto.Result) C.buffer_result_t {
	frame := buildResultFrame(result)

	return C.buffer_result_t{
		data: C.CBytes(frame),
		len:  C.int(len(frame)),
		err:  nil,
	}
}

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

	return frameResult(res)
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

	return frameResult(res)
}

//export waitAnyTimeout
func waitAnyTimeout(ms C.int) C.buffer_result_t {
	res, err := handler.WaitAnyTimeout(int(ms))

	if err != nil {
		// A timeout is not an error: signal it with a distinct, non-"error:"
		// sentinel the PHP side maps to "no result yet".
		if errors.Is(err, handler2.ErrWaitTimeout) {
			return C.buffer_result_t{data: nil, len: 0, err: C.CString("timeout")}
		}

		return C.buffer_result_t{
			data: nil,
			len:  0,
			err:  C.CString("error: " + err.Error()),
		}
	}

	return frameResult(res)
}

//export tasksCount
func tasksCount() int {
	return handler.GetTasksCount()
}

//export stopFlow
func stopFlow(fk *C.char) {
	handler.StopFlow(C.GoString(fk))
}

//export httpStopAccepting
func httpStopAccepting(fk *C.char) {
	httpserver_feature.StopAccepting(C.GoString(fk))
}

// logLine accepts a pre-formatted log line from PHP and hands it to the async logger,
// which writes it to stdout from a background goroutine. Fire-and-forget: it does not
// go through the flow/task machinery and returns immediately, so PHP's single-threaded
// loop never blocks on log I/O.
//
//export logLine
func logLine(text *C.char) {
	logger.Write(C.GoString(text))
}

//export destroy
func destroy() {
	// Flush any buffered log lines before tearing the runtime down.
	logger.Flush()

	handler.Destroy()
}

//export version
func version() *C.char {
	return C.CString("0.2.3")
}

func main() {}
