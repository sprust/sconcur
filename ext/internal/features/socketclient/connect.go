package socketclient_feature

import (
	"bufio"
	"context"
	"net"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/features/socketclient/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/socket"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.StateContract = (*connectionState)(nil)

// Default connection tuning, used as a fallback when the PHP side sends a zero value
// (the PHP defaults normally supply these). readTimeout keeps 0 as "disabled".
const (
	defaultConnectTimeout  = 10 * time.Second
	defaultWriteTimeout    = 30 * time.Second
	defaultMaxMessageBytes = 1 << 20  // 1 MiB
	readBufferSize         = 64 << 10 // 64 KiB
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
// connection metadata (id + addresses), every subsequent Next returns the next
// inbound frame (delegated to the shared socket.MessageState). Implements
// contracts.StateContract — the client mirror of the server's per-connection inbound
// stream, with the metadata prepended.
type connectionState struct {
	mutex        sync.Mutex
	message      *dto.Message
	connectionId string
	remoteAddr   string
	localAddr    string
	startTime    time.Time
	metaSent     bool
	inbound      *socket.MessageState
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
		}

		serialized, err := msgpack.Marshal(meta)

		if err != nil {
			return dto.NewErrorResult(s.message, errFactory.ByErr("marshal connection meta", err))
		}

		return dto.NewSuccessResultWithNext(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
	}

	s.mutex.Unlock()

	// MessageState guards its own reads; not holding s.mutex here lets Close close the
	// connection (unblocking an in-flight read) without deadlocking.
	return s.inbound.Next()
}

func (s *connectionState) Close() {
	// Close the connection first (unblocks any in-flight inbound read), then run the
	// inbound state's own Close (a no-op). cleanup is idempotent.
	s.cleanup()
	s.inbound.Close()
}

// handleConnect dials the remote address and registers the connection as a streaming
// state: the first result carries the connection metadata, and the connection lives
// for as long as the issuing flow does (the read side via next, the write side via
// the command loop). On flow stop / deadline the state's Close tears it down.
func (f *SocketClientFeature) handleConnect(task *tasks.Task, raw msgpack.RawMessage) {
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

	dialer := &net.Dialer{}

	conn, err := dialer.DialContext(dialContext, "tcp", payload.Address)

	if err != nil {
		// Connection refused / DNS failure / dial timeout: a network-class error.
		task.AddResult(dto.NewErrorResult(message, networkErrorPayload(err.Error())))

		return
	}

	connectionId := socket.NextConnectionId(message.FlowKey)

	pending := &socket.PendingConnection{
		Conn:      conn,
		Commands:  make(chan socket.WriteCommand),
		Abandoned: make(chan struct{}),
	}

	pendingConnections.Store(connectionId, pending)

	var cleanupOnce sync.Once

	cleanup := func() {
		cleanupOnce.Do(func() {
			pendingConnections.Delete(connectionId)

			_ = conn.Close()
		})
	}

	reader := bufio.NewReaderSize(conn, readBufferSize)

	state := &connectionState{
		message:      message,
		connectionId: connectionId,
		remoteAddr:   conn.RemoteAddr().String(),
		localAddr:    conn.LocalAddr().String(),
		startTime:    startTime,
		inbound: socket.NewMessageState(
			message,
			conn,
			reader,
			config.readTimeout,
			config.maxMessageBytes,
			errFactory,
		),
		cleanup: cleanup,
	}

	// The write loop applies Send/Close commands until the handler closes the
	// connection or the flow stops. When it returns, release a late writer and tear
	// the connection down (DeleteState → connectionState.Close → cleanup).
	go func() {
		socket.ConsumeCommands(task.GetContext(), pending, config.writeTimeout)

		close(pending.Abandoned)

		states.Get().DeleteState(message.TaskKey)
	}()

	// Start the streaming state: the first Next returns the connection metadata with
	// HasNext=true, keeping the state alive so inbound frames can be pulled via next.
	// states.Start hooks Close on flow stop / deadline.
	result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)

	if err != nil {
		cleanup()

		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("connect", err)))

		return
	}

	task.AddResult(result)
}
