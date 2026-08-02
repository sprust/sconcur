package main

/*
#cgo CFLAGS: -D_GNU_SOURCE

#include <stdlib.h>

typedef struct {
	void *data;
	int len;
	char *err;
} buffer_result_t;

// Defined in sconcur.c: atomically requests a VM interrupt on the PHP thread.
extern void sconcur_request_vm_interrupt(void);
*/
import "C"
import (
	"encoding/binary"
	"errors"
	"sconcur/internal/dto"
	httpserver_feature "sconcur/internal/features/httpserver"
	socketserver_feature "sconcur/internal/features/socketserver"
	wsserver_feature "sconcur/internal/features/wsserver"
	handler2 "sconcur/internal/handler"
	"sconcur/internal/logger"
	"sconcur/internal/types"
	"sync"
	"time"
	"unsafe"
)

// Result frame layout (Go -> PHP). The envelope is a fixed binary header, not
// MessagePack, so the result is never double-encoded: only the feature payload
// stays MessagePack and is decoded once on the PHP side. Mirrors how push passes
// its envelope as separate arguments. Must match Extension::parseWaitResponse.
//
//	[0]      flags    uint8  (bit0 isError, bit1 hasNext)
//	[1]      method   length uint8
//	[2:6]    execMs   uint32 (big-endian)
//	[6:8]    flowKey  length uint16 (big-endian)
//	[8:10]   taskKey  length uint16 (big-endian)
//	[10:]    method bytes, then flowKey bytes, then taskKey bytes, then the raw
//	         payload (the rest)
const (
	frameHeaderSize  = 10
	frameFlagError   = 1 << 0
	frameFlagHasNext = 1 << 1
)

// resultFrameSize is the framed size of one result.
func resultFrameSize(result *dto.Result) int {
	return frameHeaderSize + len(result.Method) + len(result.FlowKey) + len(result.TaskKey) + len(result.Payload)
}

// appendResultFrame appends a result envelope to dst as the fixed binary header
// followed by the raw (already-encoded) payload bytes.
func appendResultFrame(dst []byte, result *dto.Result) []byte {
	var flags byte

	if result.IsError {
		flags |= frameFlagError
	}

	if result.HasNext {
		flags |= frameFlagHasNext
	}

	dst = append(dst, flags, byte(len(result.Method)))
	dst = binary.BigEndian.AppendUint32(dst, uint32(result.ExecutionMs))
	dst = binary.BigEndian.AppendUint16(dst, uint16(len(result.FlowKey)))
	dst = binary.BigEndian.AppendUint16(dst, uint16(len(result.TaskKey)))
	dst = append(dst, result.Method...)
	dst = append(dst, result.FlowKey...)
	dst = append(dst, result.TaskKey...)
	dst = append(dst, result.Payload...)

	return dst
}

// buildResultFrame serializes a result envelope as the fixed binary header followed
// by the raw (already-encoded) payload bytes.
func buildResultFrame(result *dto.Result) []byte {
	return appendResultFrame(make([]byte, 0, resultFrameSize(result)), result)
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

// buildResultBatchFrame concatenates result frames into the multiframe the PHP
// side iterates: [count uint16][frameLen uint32][frame]... (big-endian), each
// inner frame in the exact single-result buildResultFrame format. One exact
// allocation, each frame written in place. Must match
// Extension::parseWaitBatchResponse.
func buildResultBatchFrame(results []*dto.Result) []byte {
	total := 2

	for _, result := range results {
		total += 4 + resultFrameSize(result)
	}

	batch := make([]byte, 2, total)
	binary.BigEndian.PutUint16(batch[0:2], uint16(len(results)))

	for _, result := range results {
		batch = binary.BigEndian.AppendUint32(batch, uint32(resultFrameSize(result)))
		batch = appendResultFrame(batch, result)
	}

	return batch
}

// frameResultBatch builds the buffer_result_t carrying a framed result batch.
func frameResultBatch(results []*dto.Result) C.buffer_result_t {
	batch := buildResultBatchFrame(results)

	return C.buffer_result_t{
		data: C.CBytes(batch),
		len:  C.int(len(batch)),
		err:  nil,
	}
}

var handler *handler2.Handler

func init() {
	handler = handler2.NewHandler()
}

// Preemption timer (see .ai/plans/coroutine-switcher.md, phase 2): while armed, a
// goroutine periodically requests a VM interrupt so the C-side handler can park
// the currently running coroutine between opcodes.
var (
	preemptionMutex sync.Mutex
	preemptionStop  chan struct{}
)

//export preemptionArm
func preemptionArm(quantumMs C.int) {
	preemptionMutex.Lock()
	defer preemptionMutex.Unlock()

	if preemptionStop != nil {
		close(preemptionStop)
	}

	stop := make(chan struct{})
	preemptionStop = stop

	interval := time.Duration(int(quantumMs)) * time.Millisecond

	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()

		for {
			select {
			case <-stop:
				return
			case <-ticker.C:
				C.sconcur_request_vm_interrupt()
			}
		}
	}()
}

//export preemptionDisarm
func preemptionDisarm() {
	preemptionMutex.Lock()
	defer preemptionMutex.Unlock()

	if preemptionStop != nil {
		close(preemptionStop)
		preemptionStop = nil
	}
}

//export ping
func ping(str *C.char) *C.char {
	return C.CString("ping: " + C.GoString(str))
}

//export push
func push(
	fk *C.char,
	fkLen C.int,
	mt *C.char,
	mtLen C.int,
	tk *C.char,
	tkLen C.int,
	pl unsafe.Pointer,
	plLen C.int,
) *C.char {
	msg := &dto.Message{
		FlowKey: C.GoStringN(fk, fkLen),
		Method:  types.Method(C.GoStringN(mt, mtLen)),
		TaskKey: C.GoStringN(tk, tkLen),
		Payload: C.GoBytes(pl, plLen),
		IsNext:  false,
	}

	err := handler.Push(msg)

	if err != nil {
		return C.CString("error: push: " + err.Error())
	}

	// NULL, not an allocated empty string: success is the hot path, and the C
	// side turns NULL into the interned empty string — no malloc/free per push.
	return nil
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

	// NULL on success, like push: the C side interns the empty string.
	return nil
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

//export waitAnyBatch
func waitAnyBatch(max C.int) C.buffer_result_t {
	results, err := handler.WaitAnyBatch(int(max))

	if err != nil {
		return C.buffer_result_t{
			data: nil,
			len:  0,
			err:  C.CString("error: " + err.Error()),
		}
	}

	return frameResultBatch(results)
}

//export waitAnyTimeoutBatch
func waitAnyTimeoutBatch(ms C.int, max C.int) C.buffer_result_t {
	results, err := handler.WaitAnyTimeoutBatch(int(ms), int(max))

	if err != nil {
		// A timeout is not an error: signal it with a distinct, non-"error:"
		// sentinel the PHP side maps to "no results yet".
		if errors.Is(err, handler2.ErrWaitTimeout) {
			return C.buffer_result_t{data: nil, len: 0, err: C.CString("timeout")}
		}

		return C.buffer_result_t{
			data: nil,
			len:  0,
			err:  C.CString("error: " + err.Error()),
		}
	}

	return frameResultBatch(results)
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

//export socketStopAccepting
func socketStopAccepting(fk *C.char) {
	socketserver_feature.StopAccepting(C.GoString(fk))
}

//export wsStopAccepting
func wsStopAccepting(fk *C.char) {
	wsserver_feature.StopAccepting(C.GoString(fk))
}

//export destroy
func destroy() {
	// Stop the preemption timer and flush any buffered log lines before tearing
	// the runtime down.
	preemptionDisarm()
	logger.Flush()

	handler.Destroy()
}

//export version
func version() *C.char {
	return C.CString("0.9.0")
}

func main() {}
