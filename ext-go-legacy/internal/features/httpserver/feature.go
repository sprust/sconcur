package httpserver_feature

import (
	"context"
	"errors"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/httpserver/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/logger"
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

// serverStates maps a server flow key to its *serverState, so StopAccepting can
// find the listener to close on graceful shutdown.
var serverStates sync.Map

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

	// Registered by flow key so a graceful shutdown can stop accepting early
	// (close the listener) without cancelling in-flight requests. Cleaned in Close.
	serverStates.Store(message.FlowKey, state)

	// A hard stopFlow (no prior StopAccepting) must still tear the listener and
	// the telemetry pusher down: Close rides the flow context, as the states
	// registry's AfterFunc did before the stream became self-pumping.
	context.AfterFunc(task.GetContext(), state.Close)

	// The request stream is self-pumping: every accepted request is published as
	// a stream result as soon as the previous one is consumed, so PHP never pays
	// a next() crossing (plus a task and a goroutine) per request. Backpressure
	// is layered: AddResult blocks on the shared results buffer, the requests
	// channel buffers accepts, and beyond that ServeHTTP itself blocks. The
	// stream ends with the first no-next result (server stopped).
	go func() {
		for {
			result := state.Next()

			task.AddResult(result)

			if !result.HasNext {
				return
			}
		}
	}()
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
		failRespond(task, errFactory.ByErr("parse respond requestId", err))

		return
	}

	value, ok := pendingRequests.Load(idOnly.RequestId)

	if !ok {
		// The connection is already gone (answered or disconnected): nothing to do.
		failRespond(task, errFactory.ByText("unknown requestId "+idOnly.RequestId))

		return
	}

	pending, ok := value.(*pendingRequest)

	if !ok {
		failRespond(task, errFactory.ByText("bad pending request"))

		return
	}

	var payload payloads.RespondPayload

	if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
		// Malformed payload: answer the client with a 500 instead of hanging.
		_ = f.dispatch(task, pending, writeCommand{kind: writeFull, status: 500, body: "Internal Server Error"})

		failRespond(task, errFactory.ByErr("parse respond payload", err))

		return
	}

	command := writeCommand{
		kind:    writeKind(payload.Op),
		status:  payload.Status,
		headers: payload.Headers,
		body:    payload.Body,
	}

	// A fire-and-forget write (the final write of a full response): the PHP
	// coroutine does not await this task — it finishes (and stops its flow)
	// right after the push, possibly before this goroutine runs, so the
	// handover must not select on the already-cancelled flow context, and no
	// result is published — success or failure. A failed write was equally
	// invisible before (the coroutine died after it and the groupless spawn
	// dropped the error).
	if payload.NoResult {
		f.dispatchFireAndForget(pending, command)

		return
	}

	if err := f.dispatch(task, pending, command); err != nil {
		failRespond(task, errFactory.ByErr("write response", err))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

// failRespond publishes a respond failure as the task's error result and, for a
// detached (fire-and-forget) task, also logs it. A detached task carries no flow,
// so Handler.deliver finds none and drops the result before it ever crosses to
// PHP: without the log line such a failure — a malformed payload, an unknown
// request id — would be invisible on both sides.
func failRespond(task *tasks.Task, text string) {
	message := task.GetMessage()

	if message.FlowKey == "" {
		logger.Write("sconcur httpServer: detached respond failed: " + text + "\n")
	}

	task.AddResult(dto.NewErrorResult(message, text))
}

// dispatchFireAndForget hands a write command to the connection goroutine
// without flow-context abort and without waiting for the write outcome. The
// connection-side guards still bound the handover: pending.abandoned closes as
// soon as ServeHTTP stops consuming (handler timeout, dead connection), so this
// never hangs past the request's own lifetime.
//
// command.done stays nil — nobody waits for the outcome, so the channel is not
// allocated at all (one allocation per request on the hot path). The connection
// side reports through writeCommand.report, which skips a nil channel instead of
// blocking on it forever.
func (f *HttpFeature) dispatchFireAndForget(pending *pendingRequest, command writeCommand) {
	select {
	case pending.commands <- command:
	case <-pending.abandoned:
	}
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

// StopAccepting closes the listener of the given server flow without cancelling
// in-flight requests. On a SO_REUSEPORT pool this lets the kernel route new
// connections to sibling processes while this one drains. No-op if unknown.
func StopAccepting(flowKey string) {
	value, ok := serverStates.Load(flowKey)

	if !ok {
		return
	}

	if state, ok := value.(*serverState); ok {
		state.stopAccepting()
	}
}
