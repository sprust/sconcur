package socketclient_feature

import (
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/socketclient/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/socket"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*SocketClientFeature)(nil)

var once sync.Once
var instance *SocketClientFeature

var errFactory = errs.NewErrorsFactory("socketClient")

// networkErrorMarker is prefixed onto a dial-failure payload so the PHP side maps it
// to SocketClientConnectException (mirrors the HTTP client's net:/req: markers).
const networkErrorMarker = "net"

// pendingConnections maps a connectionId to the rendezvous its write loop waits on
// for the PHP handler's send/close commands. Keyed globally so a Send/Close command
// (arriving on a different flow) can find it.
var pendingConnections sync.Map

// SocketClientFeature dials outbound TCP connections and streams their inbound frames
// to PHP, mirroring the socket server's per-connection handling but initiated by a
// dial instead of an accept. Singleton.
type SocketClientFeature struct{}

func Get() *SocketClientFeature {
	once.Do(func() {
		instance = &SocketClientFeature{}
	})

	return instance
}

func (f *SocketClientFeature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var envelope payloads.Envelope

	if err := msgpack.Unmarshal(message.Payload, &envelope); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse envelope", err)))

		return
	}

	switch types.SocketClientCommand(envelope.Command) {
	case types.SocketClientConnect:
		f.handleConnect(task, envelope.Params)
	case types.SocketClientSend:
		f.handleSend(task, envelope.Params)
	case types.SocketClientClose:
		f.handleClose(task, envelope.Params)
	default:
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown command")))
	}
}

// handleSend pushes one length-prefixed frame to the connection's peer.
func (f *SocketClientFeature) handleSend(task *tasks.Task, raw msgpack.RawMessage) {
	message := task.GetMessage()

	var params payloads.SendParams

	if err := msgpack.Unmarshal(raw, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse send params", err)))

		return
	}

	f.dispatch(task, params.ConnectionId, socket.WriteCommand{Kind: socket.OpFrame, Data: params.Data})
}

// handleClose closes the connection.
func (f *SocketClientFeature) handleClose(task *tasks.Task, raw msgpack.RawMessage) {
	message := task.GetMessage()

	var params payloads.CloseParams

	if err := msgpack.Unmarshal(raw, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse close params", err)))

		return
	}

	f.dispatch(task, params.ConnectionId, socket.WriteCommand{Kind: socket.OpClose})
}

// dispatch routes one action (write a frame, or close) to the connection's write loop,
// keyed by connection id, and waits for it to be applied (write backpressure).
func (f *SocketClientFeature) dispatch(task *tasks.Task, connectionId string, command socket.WriteCommand) {
	message := task.GetMessage()
	startTime := time.Now()

	if connectionId == "" {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("empty connectionId")))

		return
	}

	value, ok := pendingConnections.Load(connectionId)

	if !ok {
		// The connection is already gone (closed or disconnected): nothing to do.
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown connectionId "+connectionId)))

		return
	}

	pending, ok := value.(*socket.PendingConnection)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("bad pending connection")))

		return
	}

	if err := socket.Dispatch(task.GetContext(), pending, command); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("write", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

func networkErrorPayload(text string) string {
	return networkErrorMarker + ": " + text
}
