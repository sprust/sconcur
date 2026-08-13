package socketserver_feature

import (
	"context"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/socketserver/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/socket"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"sync"
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

// handleServe opens the listener and starts the self-pumping accept stream: each
// accepted connection is delivered to PHP as the next stream result.
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

	// A hard stopFlow (no prior StopAccepting) must still tear the listener and
	// the telemetry pusher down: Close rides the flow context, as the states
	// registry's AfterFunc did before the stream became self-pumping.
	context.AfterFunc(task.GetContext(), state.Close)

	// The accept stream is self-pumping (mirrors the HTTP server): every accepted
	// connection is published as a stream result as soon as the previous one is
	// consumed, so PHP pays no next() crossing per connection. The stream ends
	// with the first no-next result (server stopped).
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

	pending, ok := value.(*socket.PendingConnection)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("bad pending connection")))

		return
	}

	var payload payloads.RespondPayload

	if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse respond payload", err)))

		return
	}

	command := socket.WriteCommand{
		Kind: socket.WriteKind(payload.Op),
		Data: payload.Data,
	}

	if err := socket.Dispatch(task.GetContext(), pending, command); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("write response", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
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
