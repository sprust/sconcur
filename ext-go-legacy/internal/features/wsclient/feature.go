package wsclient_feature

import (
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/wsclient/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"sconcur/internal/ws"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*WsClientFeature)(nil)

var once sync.Once
var instance *WsClientFeature

var errFactory = errs.NewErrorsFactory("wsClient")

// networkErrorMarker is prefixed onto a dial-failure payload so the PHP side maps it to
// WsClientConnectException (mirrors the socket client's net: marker).
const networkErrorMarker = "net"

// pendingConnections maps a connectionId to the rendezvous its write loop waits on for
// the PHP handler's send/close commands. Keyed globally so a Send/Close command
// (arriving on a different flow) can find it.
var pendingConnections sync.Map

// WsClientFeature dials outbound WebSocket connections and streams their inbound
// messages to PHP, mirroring the ws server's per-connection handling but initiated by a
// dial instead of an accept. Singleton.
type WsClientFeature struct{}

func Get() *WsClientFeature {
	once.Do(func() {
		instance = &WsClientFeature{}
	})

	return instance
}

func (f *WsClientFeature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var envelope payloads.Envelope

	if err := msgpack.Unmarshal(message.Payload, &envelope); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse envelope", err)))

		return
	}

	switch envelope.Command {
	case types.WsClientConnect:
		f.handleConnect(task, envelope.Params)
	case types.WsClientSend:
		f.handleSend(task, envelope.Params)
	case types.WsClientClose:
		f.handleClose(task, envelope.Params)
	default:
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown command")))
	}
}

// handleSend pushes one message (text or binary) to the connection's peer.
func (f *WsClientFeature) handleSend(task *tasks.Task, raw msgpack.RawMessage) {
	message := task.GetMessage()

	var params payloads.SendParams

	if err := msgpack.Unmarshal(raw, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse send params", err)))

		return
	}

	f.dispatch(task, params.ConnectionId, ws.WriteCommand{
		Kind:        ws.OpFrame,
		MessageType: ws.MessageTypeFromCode(params.MessageType),
		Data:        []byte(params.Data),
	})
}

// handleClose closes the connection.
func (f *WsClientFeature) handleClose(task *tasks.Task, raw msgpack.RawMessage) {
	message := task.GetMessage()

	var params payloads.CloseParams

	if err := msgpack.Unmarshal(raw, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse close params", err)))

		return
	}

	f.dispatch(task, params.ConnectionId, ws.WriteCommand{Kind: ws.OpClose})
}

// dispatch routes one action (write a message, or close) to the connection's write loop,
// keyed by connection id, and waits for it to be applied (write backpressure).
func (f *WsClientFeature) dispatch(task *tasks.Task, connectionId string, command ws.WriteCommand) {
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

	pending, ok := value.(*ws.PendingConnection)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("bad pending connection")))

		return
	}

	if err := ws.Dispatch(task.GetContext(), pending, command); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("write", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

func networkErrorPayload(text string) string {
	return networkErrorMarker + ": " + text
}
