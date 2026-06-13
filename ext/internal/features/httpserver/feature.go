package httpserver_feature

import (
	"net"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/httpserver/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*HttpFeature)(nil)

var once sync.Once
var instance *HttpFeature

var errFactory = errs.NewErrorsFactory("httpServer")

// pendingRequests maps a requestId to the channel its connection goroutine waits
// on for the PHP handler's response. Keyed globally so httpRespond (arriving on a
// different flow) can find it.
var pendingRequests sync.Map

var requestCounter atomic.Int64

type HttpFeature struct{}

func Get() *HttpFeature {
	once.Do(func() {
		instance = &HttpFeature{}
	})

	return instance
}

func (f *HttpFeature) Handle(task *tasks.Task) {
	switch task.GetMessage().Method {
	case types.MethodHttpServe:
		f.handleServe(task)
	case types.MethodHttpRespond:
		f.handleRespond(task)
	default:
		task.AddResult(
			dto.NewErrorResult(task.GetMessage(), errFactory.ByText("unknown method")),
		)
	}
}

// handleServe opens the listener and registers the server as a streaming state:
// each accepted request is delivered to PHP as the next batch.
func (f *HttpFeature) handleServe(task *tasks.Task) {
	message := task.GetMessage()
	startTime := time.Now()

	var payload payloads.ServePayload

	if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse serve payload", err)))

		return
	}

	listener, err := net.Listen("tcp", payload.Address)

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("listen", err)))

		return
	}

	state := newServerState(task.GetContext(), message, listener, startTime)

	result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)

	if err != nil {
		state.Close()

		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("serve", err)))

		return
	}

	task.AddResult(result)
}

// handleRespond routes a PHP handler's response to the waiting connection.
func (f *HttpFeature) handleRespond(task *tasks.Task) {
	message := task.GetMessage()
	startTime := time.Now()

	var payload payloads.RespondPayload

	if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse respond payload", err)))

		return
	}

	value, ok := pendingRequests.Load(payload.RequestId)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown requestId "+payload.RequestId)))

		return
	}

	responseCh, ok := value.(chan respondData)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("bad response channel")))

		return
	}

	select {
	case responseCh <- respondData{status: payload.Status, headers: payload.Headers, body: payload.Body}:
	default:
		// Connection already gone or already answered: nothing to do.
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

func nextRequestId(flowKey string) string {
	return flowKey + ":r:" + strconv.FormatInt(requestCounter.Add(1), 10)
}
