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
		f.handleRespond(task, envelope.Params, socket.OpFrame)
	case types.SocketClientClose:
		f.handleRespond(task, envelope.Params, socket.OpClose)
	default:
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown command")))
	}
}

// handleRespond routes one action (write a frame, or close) to the connection's write
// loop, keyed by connection id. Shared by Send (OpFrame) and Close (OpClose).
func (f *SocketClientFeature) handleRespond(task *tasks.Task, raw msgpack.RawMessage, kind socket.WriteKind) {
	message := task.GetMessage()
	startTime := time.Now()

	var idOnly struct {
		ConnectionId string `msgpack:"cid"`
	}

	if err := msgpack.Unmarshal(raw, &idOnly); err != nil || idOnly.ConnectionId == "" {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse connectionId", err)))

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

	// A Send carries the frame bytes; a Close ignores them.
	data := ""

	if kind == socket.OpFrame {
		var sendParams payloads.SendParams

		if err := msgpack.Unmarshal(raw, &sendParams); err != nil {
			task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse send params", err)))

			return
		}

		data = sendParams.Data
	}

	command := socket.WriteCommand{
		Kind: kind,
		Data: data,
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
