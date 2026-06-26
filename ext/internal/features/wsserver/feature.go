package wsserver_feature

import (
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/wsserver/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"sconcur/internal/ws"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*WsFeature)(nil)

var once sync.Once
var instance *WsFeature

var errFactory = errs.NewErrorsFactory("wsServer")

// pendingConnections maps a connectionId to the rendezvous its write loop waits on for
// the PHP handler's responses. Keyed globally so wsRespond (arriving on a different
// flow) can find it.
var pendingConnections sync.Map

// serverStates maps a server flow key to its *serverState, so StopAccepting can find
// the listener and in-flight connections to drain on graceful shutdown.
var serverStates sync.Map

type WsFeature struct{}

func Get() *WsFeature {
	once.Do(func() {
		instance = &WsFeature{}
	})

	return instance
}

func (f *WsFeature) Handle(task *tasks.Task) {
	switch task.GetMessage().Method {
	case types.MethodWsServe:
		f.handleServe(task)
	case types.MethodWsRespond:
		f.handleRespond(task)
	default:
		task.AddResult(
			dto.NewErrorResult(task.GetMessage(), errFactory.ByText("unknown method")),
		)
	}
}

// handleServe opens the listener and registers the server as a streaming state: each
// upgraded connection is delivered to PHP as the next batch.
func (f *WsFeature) handleServe(task *tasks.Task) {
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

// handleRespond routes one action (write a message, or close) from a PHP connection
// handler to the waiting connection's write loop.
func (f *WsFeature) handleRespond(task *tasks.Task) {
	message := task.GetMessage()
	startTime := time.Now()

	// Decode the connection id on its own first: a struct with only this field ignores
	// every other key, so we can route even if the rest of the payload is malformed.
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

	pending, ok := value.(*ws.PendingConnection)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("bad pending connection")))

		return
	}

	var payload payloads.RespondPayload

	if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse respond payload", err)))

		return
	}

	command := ws.WriteCommand{
		Kind:        ws.WriteKind(payload.Op),
		MessageType: ws.MessageTypeFromCode(payload.MessageType),
		Data:        []byte(payload.Data),
	}

	if err := ws.Dispatch(task.GetContext(), pending, command); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("write response", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

// StopAccepting closes the listener of the given server flow and drains its in-flight
// connections, so on a SO_REUSEPORT pool the kernel routes new connections to siblings
// while this one drains. No-op if unknown.
func StopAccepting(flowKey string) {
	value, ok := serverStates.Load(flowKey)

	if !ok {
		return
	}

	if state, ok := value.(*serverState); ok {
		state.stopAccepting()
	}
}
