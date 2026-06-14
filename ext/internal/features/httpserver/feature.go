package httpserver_feature

import (
	"errors"
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

// errAbandoned is returned to a handler coroutine when the connection goroutine
// has stopped consuming its writes (handler timeout, or the connection is gone),
// so the coroutine unwinds instead of blocking on the response channel forever.
var errAbandoned = errors.New("request abandoned")

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

	listener, err := listen(payload.Address, payload.ReusePort)

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("listen", err)))

		return
	}

	state := newServerState(task.GetContext(), message, listener, startTime, configFromPayload(payload))

	result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)

	if err != nil {
		state.Close()

		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("serve", err)))

		return
	}

	task.AddResult(result)
}

// handleRespond routes one write command (a one-shot response, or a head/chunk/
// end of a streamed one) from a PHP handler to the waiting connection. It never
// leaves the connection hanging: as long as the request id resolves, the client
// always gets an answer — a 500 if the payload itself is malformed.
func (f *HttpFeature) handleRespond(task *tasks.Task) {
	message := task.GetMessage()
	startTime := time.Now()

	// Decode the request id on its own first: a struct with only this field
	// ignores every other key, so we can always route a response back even if the
	// rest of the payload is malformed.
	var idOnly struct {
		RequestId string `msgpack:"rid"`
	}

	if err := msgpack.Unmarshal(message.Payload, &idOnly); err != nil || idOnly.RequestId == "" {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse respond requestId", err)))

		return
	}

	value, ok := pendingRequests.Load(idOnly.RequestId)

	if !ok {
		// The connection is already gone (answered or disconnected): nothing to do.
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown requestId "+idOnly.RequestId)))

		return
	}

	pending, ok := value.(*pendingRequest)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("bad pending request")))

		return
	}

	var payload payloads.RespondPayload

	if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
		// Malformed payload: answer the client with a 500 instead of hanging.
		_ = f.dispatch(task, pending, writeCommand{kind: writeFull, status: 500, body: "Internal Server Error"})

		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse respond payload", err)))

		return
	}

	command := writeCommand{
		kind:    writeKind(payload.Op),
		status:  payload.Status,
		headers: payload.Headers,
		body:    payload.Body,
	}

	if err := f.dispatch(task, pending, command); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("write response", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

// dispatch hands one write command to the connection goroutine and waits for it
// to be applied, so the handler coroutine only continues once the bytes hit the
// wire (write backpressure). It returns the client write error, if any —
// including errAbandoned when ServeHTTP has stopped consuming (handler timeout or
// the connection is gone), so the handler coroutine unwinds instead of hanging on
// the unbuffered commands channel.
func (f *HttpFeature) dispatch(task *tasks.Task, pending *pendingRequest, command writeCommand) error {
	command.done = make(chan error, 1)

	select {
	case pending.commands <- command:
	case <-pending.abandoned:
		return errAbandoned
	case <-task.GetContext().Done():
		return nil
	}

	// Prefer a delivered result over a late abandon signal: if the write was
	// applied, honor it even if ServeHTTP returned right after.
	select {
	case err := <-command.done:
		return err
	default:
	}

	select {
	case err := <-command.done:
		return err
	case <-pending.abandoned:
		return errAbandoned
	case <-task.GetContext().Done():
		return nil
	}
}

func nextRequestId(flowKey string) string {
	return flowKey + ":r:" + strconv.FormatInt(requestCounter.Add(1), 10)
}
