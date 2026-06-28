package wsclient_feature

import (
	"context"
	"net/url"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/features/wsclient/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/socket"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sconcur/internal/ws"
	"sync"
	"time"

	"github.com/coder/websocket"
	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.StateContract = (*connectionState)(nil)

// Default connection tuning, used as a fallback when the PHP side sends a zero value
// (the PHP defaults normally supply these). readTimeout keeps 0 as "disabled".
const (
	defaultConnectTimeout  = 10 * time.Second
	defaultWriteTimeout    = 30 * time.Second
	defaultMaxMessageBytes = 1 << 20 // 1 MiB

	// inboundQueueSize buffers inbound messages the read goroutine has read but the
	// caller has not yet pulled via read().
	inboundQueueSize = 256
)

// connectionConfig holds the resolved tuning for one dialed connection.
type connectionConfig struct {
	connectTimeout  time.Duration
	readTimeout     time.Duration
	writeTimeout    time.Duration
	maxMessageBytes int
}

func configFromPayload(payload payloads.ConnectParams) connectionConfig {
	return connectionConfig{
		connectTimeout:  msOrDefault(payload.ConnectTimeoutMs, defaultConnectTimeout),
		readTimeout:     time.Duration(max(payload.ReadTimeoutMs, 0)) * time.Millisecond,
		writeTimeout:    msOrDefault(payload.WriteTimeoutMs, defaultWriteTimeout),
		maxMessageBytes: intOrDefault(payload.MaxMessageBytes, defaultMaxMessageBytes),
	}
}

func msOrDefault(ms int, fallback time.Duration) time.Duration {
	if ms <= 0 {
		return fallback
	}

	return time.Duration(ms) * time.Millisecond
}

func intOrDefault(value int, fallback int) int {
	if value <= 0 {
		return fallback
	}

	return value
}

// connectionState streams one dialed connection to PHP: the first Next returns the
// connection metadata (id + addresses + subprotocol), every subsequent Next returns the
// next inbound message (text/binary). The connection is pumped by a read goroutine — so
// control frames (server ping/close) are always processed — which feeds data messages
// through the messages channel. Implements contracts.StateContract — the client mirror
// of the server's per-connection inbound stream, with the metadata prepended.
type connectionState struct {
	mutex        sync.Mutex
	ctx          context.Context
	message      *dto.Message
	connectionId string
	remoteAddr   string
	localAddr    string
	subprotocol  string
	startTime    time.Time
	metaSent     bool
	messages     chan ws.InboundMessage
	cleanup      func()
}

func (s *connectionState) Next() *dto.Result {
	s.mutex.Lock()

	if !s.metaSent {
		s.metaSent = true

		s.mutex.Unlock()

		meta := payloads.ConnectionMeta{
			ConnectionId: s.connectionId,
			RemoteAddr:   s.remoteAddr,
			LocalAddr:    s.localAddr,
			Subprotocol:  s.subprotocol,
		}

		serialized, err := msgpack.Marshal(meta)

		if err != nil {
			return dto.NewErrorResult(s.message, errFactory.ByErr("marshal connection meta", err))
		}

		return dto.NewSuccessResultWithNext(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
	}

	s.mutex.Unlock()

	// Prefer an already-received message over shutdown. With a buffered message and ctx
	// both ready, a plain select would pick a case at random and could drop the buffered
	// message, returning EOF while data was waiting. A non-blocking check first guarantees
	// a message that arrived before the context was cancelled is still delivered.
	select {
	case message, ok := <-s.messages:
		return s.resultFromMessage(message, ok)
	default:
	}

	select {
	case message, ok := <-s.messages:
		return s.resultFromMessage(message, ok)
	case <-s.ctx.Done():
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}
}

// resultFromMessage turns a receive from the messages channel into a Next() result: a
// delivered message (more to come), or end-of-stream when the channel is closed (the read
// goroutine ended: peer closed, oversize message, idle timeout, shutdown).
func (s *connectionState) resultFromMessage(message ws.InboundMessage, ok bool) *dto.Result {
	if !ok {
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}

	return dto.NewSuccessResultWithNext(s.message, ws.EncodeInbound(message), helpers.CalcExecutionMs(s.startTime))
}

func (s *connectionState) Close() {
	// Close the connection (unblocks the read goroutine's in-flight read). cleanup is
	// idempotent.
	s.cleanup()
}

// handleConnect dials the remote URL and registers the connection as a streaming state:
// the first result carries the connection metadata, and the connection lives for as long
// as the issuing flow does (the read side via next, the write side via the command
// loop). On flow stop / deadline the state's Close tears it down.
func (f *WsClientFeature) handleConnect(task *tasks.Task, raw msgpack.RawMessage) {
	message := task.GetMessage()
	startTime := time.Now()

	var payload payloads.ConnectParams

	if err := msgpack.Unmarshal(raw, &payload); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse connect params", err)))

		return
	}

	config := configFromPayload(payload)

	// Dial bounded by connectTimeout and cancellable by the flow context (a flow stop
	// aborts an in-progress dial). The cancel only guards the dial; the established
	// connection outlives it.
	dialContext := task.GetContext()

	if config.connectTimeout > 0 {
		var cancel context.CancelFunc

		dialContext, cancel = context.WithTimeout(task.GetContext(), config.connectTimeout)

		defer cancel()
	}

	conn, _, err := websocket.Dial(dialContext, payload.Address, &websocket.DialOptions{
		Subprotocols: payload.Subprotocols,
	})

	if err != nil {
		// Connection refused / DNS failure / handshake failure / dial timeout: a
		// network-class error.
		task.AddResult(dto.NewErrorResult(message, networkErrorPayload(err.Error())))

		return
	}

	if config.maxMessageBytes > 0 {
		conn.SetReadLimit(int64(config.maxMessageBytes))
	} else {
		conn.SetReadLimit(-1)
	}

	connectionId := socket.NextConnectionId(message.FlowKey)

	pending := &ws.PendingConnection{
		Conn:      conn,
		Commands:  make(chan ws.WriteCommand),
		Abandoned: make(chan struct{}),
	}

	pendingConnections.Store(connectionId, pending)

	var cleanupOnce sync.Once

	cleanup := func() {
		cleanupOnce.Do(func() {
			pendingConnections.Delete(connectionId)

			_ = conn.CloseNow()
		})
	}

	messages := make(chan ws.InboundMessage, inboundQueueSize)

	go readLoop(task.GetContext(), conn, config.readTimeout, messages)

	state := &connectionState{
		ctx:          task.GetContext(),
		message:      message,
		connectionId: connectionId,
		remoteAddr:   remoteAddrFromURL(payload.Address),
		localAddr:    "",
		subprotocol:  conn.Subprotocol(),
		startTime:    startTime,
		messages:     messages,
		cleanup:      cleanup,
	}

	// Start the streaming state: the first Next returns the connection metadata with
	// HasNext=true, keeping the state alive so inbound messages can be pulled via next.
	// states.Start hooks Close on flow stop / deadline. Done before starting the write
	// loop so a Start failure leaves no goroutine behind.
	result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)

	if err != nil {
		cleanup()

		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("connect", err)))

		return
	}

	// The write loop applies Send/Close commands until the handler closes the connection
	// or the flow stops. When it returns, release a late writer and tear the connection
	// down (DeleteState → connectionState.Close → cleanup). PHP only issues Send/Close
	// after it receives this connect result, so the loop is running before any command
	// can arrive. The client never pings (pingInterval 0); the library answers server
	// pings while the read goroutine pumps the connection.
	go func() {
		ws.ConsumeCommands(task.GetContext(), pending, config.writeTimeout, 0)

		close(pending.Abandoned)

		states.Get().DeleteState(message.TaskKey)
	}()

	task.AddResult(result)
}

// readLoop continuously reads inbound messages from the connection so control frames
// (server ping/pong/close) are always processed, and feeds each data message to the
// stream. It ends — closing the messages channel — on any read error: peer close, an
// oversize message (1009), the idle timeout, or shutdown.
func readLoop(ctx context.Context, conn *websocket.Conn, readTimeout time.Duration, messages chan ws.InboundMessage) {
	defer close(messages)

	for {
		readCtx := ctx

		var cancel context.CancelFunc

		if readTimeout > 0 {
			readCtx, cancel = context.WithTimeout(ctx, readTimeout)
		}

		messageType, data, err := conn.Read(readCtx)

		if cancel != nil {
			cancel()
		}

		if err != nil {
			return
		}

		message := ws.InboundMessage{
			Binary: messageType == websocket.MessageBinary,
			Data:   data,
		}

		select {
		case messages <- message:
		case <-ctx.Done():
			return
		}
	}
}

// remoteAddrFromURL extracts the host:port from a ws:// URL for the connection
// metadata; on a parse failure it returns the raw address.
func remoteAddrFromURL(address string) string {
	parsed, err := url.Parse(address)

	if err != nil {
		return address
	}

	return parsed.Host
}
