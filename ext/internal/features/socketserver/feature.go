package socketserver_feature

import (
	"errors"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/socketserver/payloads"
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

var _ contracts.FeatureContract = (*SocketFeature)(nil)

var once sync.Once
var instance *SocketFeature

var errFactory = errs.NewErrorsFactory("socketServer")

// pendingConnections maps a connectionId to the rendezvous its write loop waits on
// for the PHP handler's responses. Keyed globally so socketRespond (arriving on a
// different flow) can find it.
var pendingConnections sync.Map

// serverStates maps a server flow key to its *serverState, so StopAccepting can find
// the listener and in-flight connections to close on graceful shutdown.
var serverStates sync.Map

var connectionCounter atomic.Int64

// errAbandoned is returned to a handler coroutine when the write loop has stopped
// consuming its commands (handler timeout, server shutdown, or the connection is
// gone), so the coroutine unwinds instead of blocking on the commands channel.
var errAbandoned = errors.New("connection abandoned")

type SocketFeature struct{}

func Get() *SocketFeature {
	once.Do(func() {
		instance = &SocketFeature{}
	})

	return instance
}

func (f *SocketFeature) Handle(task *tasks.Task) {
	switch task.GetMessage().Method {
	case types.MethodSocketServe:
		f.handleServe(task)
	case types.MethodSocketRespond:
		f.handleRespond(task)
	default:
		task.AddResult(
			dto.NewErrorResult(task.GetMessage(), errFactory.ByText("unknown method")),
		)
	}
}

// handleServe opens the listener and registers the server as a streaming state: each
// accepted connection is delivered to PHP as the next batch.
func (f *SocketFeature) handleServe(task *tasks.Task) {
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

	// Registered by flow key so a graceful shutdown can stop accepting early without
	// cancelling in-flight connections. Cleaned in Close.
	serverStates.Store(message.FlowKey, state)

	result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)

	if err != nil {
		state.Close()

		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("serve", err)))

		return
	}

	task.AddResult(result)
}

// handleRespond routes one action (write a frame, or close) from a PHP connection
// handler to the waiting connection's write loop.
func (f *SocketFeature) handleRespond(task *tasks.Task) {
	message := task.GetMessage()
	startTime := time.Now()

	// Decode the connection id on its own first: a struct with only this field
	// ignores every other key, so we can route even if the rest of the payload is
	// malformed.
	var idOnly struct {
		ConnectionId string `msgpack:"cid"`
	}

	if err := msgpack.Unmarshal(message.Payload, &idOnly); err != nil || idOnly.ConnectionId == "" {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse respond connectionId", err)))

		return
	}

	value, ok := pendingConnections.Load(idOnly.ConnectionId)

	if !ok {
		// The connection is already gone (closed or disconnected): nothing to do.
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown connectionId "+idOnly.ConnectionId)))

		return
	}

	pending, ok := value.(*pendingConnection)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("bad pending connection")))

		return
	}

	var payload payloads.RespondPayload

	if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse respond payload", err)))

		return
	}

	command := writeCommand{
		kind: writeKind(payload.Op),
		data: payload.Data,
	}

	if err := f.dispatch(task, pending, command); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("write response", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

// dispatch hands one write command to the connection's write loop and waits for it
// to be applied, so the handler coroutine only continues once the bytes hit the wire
// (write backpressure). It returns the client write error, if any — including
// errAbandoned when the write loop has stopped consuming (handler timeout, shutdown
// or the connection is gone), so the handler coroutine unwinds instead of hanging.
func (f *SocketFeature) dispatch(task *tasks.Task, pending *pendingConnection, command writeCommand) error {
	command.done = make(chan error, 1)

	select {
	case pending.commands <- command:
	case <-pending.abandoned:
		return errAbandoned
	case <-task.GetContext().Done():
		return nil
	}

	// Prefer a delivered result over a late abandon signal: if the write was applied,
	// honor it even if the write loop returned right after.
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

func nextConnectionId(flowKey string) string {
	return flowKey + ":c:" + strconv.FormatInt(connectionCounter.Add(1), 10)
}

// StopAccepting closes the listener of the given server flow and half-closes its
// in-flight connections, so on a SO_REUSEPORT pool the kernel routes new connections
// to siblings while this one drains. No-op if unknown.
func StopAccepting(flowKey string) {
	value, ok := serverStates.Load(flowKey)

	if !ok {
		return
	}

	if state, ok := value.(*serverState); ok {
		state.stopAccepting()
	}
}
