package wsserver_feature

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"sconcur/internal/dto"
	"sconcur/internal/features/wsserver/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/logger"
	"sconcur/internal/socket"
	"sconcur/internal/states"
	"sconcur/internal/stats"
	"sconcur/internal/ws"
	"strings"
	"sync"
	"time"

	"github.com/coder/websocket"
	"github.com/vmihailenco/msgpack/v5"
)

// Default server tuning, used as a fallback when the PHP side sends a zero value.
// The PHP side normally supplies these (its defaults mirror them).
const (
	defaultHandshakeTimeout = 10 * time.Second
	defaultWriteTimeout     = 30 * time.Second
	defaultPingInterval     = 30 * time.Second
	defaultShutdownTimeout  = 5 * time.Second
	defaultMaxMessageBytes  = 1 << 20 // 1 MiB

	// drainGrace bounds how long a connection may keep its handler alive after the
	// listener stops accepting; past it the connection is force-closed so a push-only
	// handler that never reads still unwinds and the server can finish draining.
	drainGrace = 2 * time.Second

	// connectionQueueSize buffers upgraded connections that ServeHTTP has handed off
	// but the PHP serve loop has not yet pulled via next(). It smooths accept bursts;
	// the real backpressure is maxConcurrency.
	connectionQueueSize = 1024

	// inboundQueueSize buffers inbound data messages the read goroutine has read but
	// the handler has not yet pulled via read(). It decouples connection pumping (so
	// control frames stay serviced) from the handler's read cadence.
	inboundQueueSize = 256
)

// serverConfig holds the resolved tuning for one server instance.
type serverConfig struct {
	handshakeTimeout time.Duration
	idleTimeout      time.Duration
	writeTimeout     time.Duration
	pingInterval     time.Duration
	shutdownTimeout  time.Duration
	maxMessageBytes  int
	maxConcurrency   int
	path             string
	allowedOrigins   []string
	subprotocols     []string
	// telemetrySocket is the collector's unix socket the worker pushes snapshots to
	// (empty = push off); serverName labels the snapshot (pool scope);
	// telemetryIntervalMs is the snapshot sampling/push cadence (0 = default).
	telemetrySocket     string
	serverName          string
	telemetryIntervalMs int
}

// configFromPayload resolves the tuning from the PHP payload, falling back to the
// defaults for any zero (unset) value where a default makes sense. idleTimeout keeps
// 0 as "disabled"; pingInterval keeps 0 as "disabled".
func configFromPayload(payload payloads.ServePayload) serverConfig {
	return serverConfig{
		handshakeTimeout:    msOrDefault(payload.HandshakeTimeoutMs, defaultHandshakeTimeout),
		idleTimeout:         time.Duration(max(payload.IdleTimeoutMs, 0)) * time.Millisecond,
		writeTimeout:        msOrDefault(payload.WriteTimeoutMs, defaultWriteTimeout),
		pingInterval:        time.Duration(max(payload.PingIntervalMs, 0)) * time.Millisecond,
		shutdownTimeout:     msOrDefault(payload.ShutdownTimeoutMs, defaultShutdownTimeout),
		maxMessageBytes:     intOrDefault(payload.MaxMessageBytes, defaultMaxMessageBytes),
		maxConcurrency:      max(payload.MaxConcurrency, 0),
		path:                payload.Path,
		allowedOrigins:      payload.AllowedOrigins,
		subprotocols:        payload.Subprotocols,
		telemetrySocket:     payload.TelemetrySocket,
		serverName:          payload.ServerName,
		telemetryIntervalMs: payload.TelemetryIntervalMs,
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

// liveConn tracks one in-flight connection so stopAccepting can end its input (close
// drain) on a graceful handover and force-close it after the grace.
type liveConn struct {
	conn      *websocket.Conn
	drain     chan struct{}
	drainOnce sync.Once
}

func (c *liveConn) startDrain() {
	c.drainOnce.Do(func() {
		close(c.drain)
	})
}

// serverState is the streaming state of one ws server: each upgraded connection is one
// "batch" pulled by PHP via next(). Implements contracts.StateContract.
//
// The network is handled by a standard net/http.Server (keep-alive, the upgrade
// handshake, timeouts); serverState is its http.Handler. A request carrying a valid
// WebSocket upgrade is accepted (coder/websocket) and handed to PHP through the
// connections channel; every other request is answered 426 Upgrade Required by Accept.
type serverState struct {
	ctx         context.Context
	message     *dto.Message
	listener    net.Listener
	httpServer  *http.Server
	config      serverConfig
	startTime   time.Time
	connections chan *payloads.ConnectionEvent
	// sem bounds concurrent in-flight connections when maxConcurrency > 0; nil means
	// unlimited.
	sem chan struct{}
	// conns tracks this server's live connections (connId → *liveConn) so stopAccepting
	// can drain them all on graceful handover.
	conns sync.Map
	// waitGroup tracks live handleConn goroutines so Close can drain them (the upgraded
	// connection is hijacked, so http.Server.Shutdown does not wait for it).
	waitGroup sync.WaitGroup
	// connectionStats holds this worker's connection counters (the stats workload);
	// pusher samples the snapshot and pushes it to the collector (no-op when no
	// telemetry socket is configured).
	connectionStats *connectionStats
	pusher          *stats.Pusher
}

func newServerState(
	ctx context.Context,
	message *dto.Message,
	listener net.Listener,
	startTime time.Time,
	config serverConfig,
) *serverState {
	connectionStats := &connectionStats{}

	state := &serverState{
		ctx:             ctx,
		message:         message,
		listener:        listener,
		config:          config,
		startTime:       startTime,
		connections:     make(chan *payloads.ConnectionEvent, connectionQueueSize),
		sem:             newSemaphore(config.maxConcurrency),
		connectionStats: connectionStats,
		pusher: stats.NewPusher(
			config.serverName,
			config.telemetrySocket,
			config.telemetryIntervalMs,
			startTime,
			connectionStats,
		),
	}

	state.pusher.Start()

	state.httpServer = &http.Server{
		Handler:           state,
		ReadHeaderTimeout: config.handshakeTimeout,
		// Tie every request context to the server's lifetime so blocked connections
		// unblock when the server stops.
		BaseContext: func(net.Listener) context.Context {
			return state.ctx
		},
	}

	go func() {
		_ = state.httpServer.Serve(listener)
	}()

	return state
}

// newSemaphore builds the concurrency limiter, or nil when unlimited (size <= 0).
func newSemaphore(size int) chan struct{} {
	if size <= 0 {
		return nil
	}

	return make(chan struct{}, size)
}

// ServeHTTP upgrades one request to a WebSocket and serves the connection. A request
// on the wrong path is answered 404; a request without a valid upgrade is answered 426
// by Accept. ServeHTTP blocks for the whole connection lifetime.
func (s *serverState) ServeHTTP(writer http.ResponseWriter, request *http.Request) {
	start := time.Now()

	// Restrict the upgrade endpoint when a path is configured.
	if s.config.path != "" && request.URL.Path != s.config.path {
		http.Error(writer, "not found", http.StatusNotFound)

		return
	}

	// Bound concurrency before the upgrade so a waiting connection holds no resources.
	// A waiting connection unblocks when a slot frees or the server stops.
	if s.sem != nil {
		select {
		case s.sem <- struct{}{}:
			defer func() { <-s.sem }()
		case <-s.ctx.Done():
			http.Error(writer, "service unavailable", http.StatusServiceUnavailable)

			return
		}
	}

	conn, err := websocket.Accept(writer, request, &websocket.AcceptOptions{
		Subprotocols:       s.config.subprotocols,
		InsecureSkipVerify: len(s.config.allowedOrigins) == 0,
		OriginPatterns:     s.config.allowedOrigins,
	})

	if err != nil {
		// Accept already wrote the handshake failure (426 / 403). Log it and stop.
		logger.Write(formatAccessLine(start, request.RemoteAddr, request.URL.Path, 0, "handshake_error"))

		return
	}

	if s.config.maxMessageBytes > 0 {
		conn.SetReadLimit(int64(s.config.maxMessageBytes))
	} else {
		conn.SetReadLimit(-1)
	}

	s.waitGroup.Add(1)
	defer s.waitGroup.Done()

	s.handleConn(start, request, conn)
}

// handleConn serves one upgraded connection: hand it to PHP as a ConnectionEvent, pump
// inbound messages in a read goroutine, then run the write loop applying the handler's
// responses until the connection closes.
func (s *serverState) handleConn(start time.Time, request *http.Request, conn *websocket.Conn) {
	remoteAddr := request.RemoteAddr
	localAddr := ""

	if addr, ok := request.Context().Value(http.LocalAddrContextKey).(net.Addr); ok {
		localAddr = addr.String()
	}

	s.connectionStats.connectionOpened()
	defer s.connectionStats.connectionClosed()

	connectionId := socket.NextConnectionId(s.message.FlowKey)

	pending := &ws.PendingConnection{
		Conn:      conn,
		Commands:  make(chan ws.WriteCommand),
		Abandoned: make(chan struct{}),
	}

	pendingConnections.Store(connectionId, pending)

	drain := make(chan struct{})
	live := &liveConn{conn: conn, drain: drain}
	s.conns.Store(connectionId, live)

	messages := make(chan ws.InboundMessage, inboundQueueSize)
	inboundKey := connectionId + ":in"

	_ = states.Get().Register(inboundKey, newMessageState(s.ctx, s.message, messages, drain))

	// The read goroutine pumps the connection (so control frames stay serviced) and
	// feeds data messages to the message stream; readCtx is cancelled on teardown.
	readCtx, cancelRead := context.WithCancel(s.ctx)

	go s.readLoop(readCtx, conn, messages)

	messageCount := 0
	status := "ok"

	defer func() {
		cancelRead()
		// Once we stop consuming, release a handler still trying to write.
		close(pending.Abandoned)
		pendingConnections.Delete(connectionId)
		s.conns.Delete(connectionId)
		states.Get().DeleteState(inboundKey)
		_ = conn.CloseNow()

		logger.Write(formatAccessLine(start, remoteAddr, request.URL.Path, messageCount, status))
	}()

	event := &payloads.ConnectionEvent{
		ConnectionId: connectionId,
		RemoteAddr:   remoteAddr,
		LocalAddr:    localAddr,
		Path:         request.URL.Path,
		Subprotocol:  conn.Subprotocol(),
	}

	select {
	case s.connections <- event:
	case <-s.ctx.Done():
		status = "shutdown"

		return
	}

	messageCount, status = ws.ConsumeCommands(s.ctx, pending, s.config.writeTimeout, s.config.pingInterval)
}

// readLoop continuously reads inbound messages from the connection so control frames
// (ping/pong/close) are always processed, and feeds each data message to the message
// stream. It ends — closing the messages channel — on any read error: peer close, an
// oversize message (1009), the idle timeout, or shutdown.
func (s *serverState) readLoop(ctx context.Context, conn *websocket.Conn, messages chan ws.InboundMessage) {
	defer close(messages)

	for {
		readCtx := ctx

		var cancel context.CancelFunc

		if s.config.idleTimeout > 0 {
			readCtx, cancel = context.WithTimeout(ctx, s.config.idleTimeout)
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

func (s *serverState) Next() *dto.Result {
	select {
	case event := <-s.connections:
		serialized, err := msgpack.Marshal(event)

		if err != nil {
			return dto.NewErrorResult(s.message, "wsServer: marshal connection: "+err.Error())
		}

		return dto.NewSuccessResultWithNext(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
	case <-s.ctx.Done():
		// Server stopped: end the stream so the PHP serve loop exits.
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}
}

// stopAccepting closes the listener (so a SO_REUSEPORT sibling takes over new
// connections) and ends the input of every in-flight connection, so a handler reading
// in a loop sees EOF while it can still write a final message. A push-only handler
// that never reads would not notice, so after a bounded grace every connection is
// force-closed too — its next write fails and the handler unwinds, letting the generic
// serve loop finish draining.
func (s *serverState) stopAccepting() {
	go func() {
		_ = s.httpServer.Shutdown(context.Background())
	}()

	s.conns.Range(func(_, value any) bool {
		if live, ok := value.(*liveConn); ok {
			live.startDrain()
		}

		return true
	})

	go s.forceCloseAfterGrace()
}

// forceCloseAfterGrace force-closes every still-live connection once the drain grace
// elapses, unless the server is already fully stopped (ctx done) by then.
func (s *serverState) forceCloseAfterGrace() {
	timer := time.NewTimer(drainGrace)
	defer timer.Stop()

	select {
	case <-timer.C:
	case <-s.ctx.Done():
		return
	}

	s.conns.Range(func(_, value any) bool {
		if live, ok := value.(*liveConn); ok {
			_ = live.conn.CloseNow()
		}

		return true
	})
}

// Close shuts the server down: stop accepting and wait for in-flight connections to
// drain (bounded by shutdownTimeout). Run on a fresh context — the task context is
// already cancelled by the time the state is closed, which is what unblocks the
// per-connection read/write loops.
func (s *serverState) Close() {
	serverStates.Delete(s.message.FlowKey)

	s.pusher.Stop()

	ctx, cancel := context.WithTimeout(context.Background(), s.config.shutdownTimeout)
	defer cancel()

	_ = s.httpServer.Shutdown(ctx)

	done := make(chan struct{})

	go func() {
		s.waitGroup.Wait()

		close(done)
	}()

	select {
	case <-done:
	case <-time.After(s.config.shutdownTimeout):
	}
}

// formatAccessLine builds one access-log line for a finished connection:
//
//	<ISO-start-time> <remoteAddr> <path> messages=<n> <status> <ms>ms
//
// where messages is the number of messages written to the client over the connection.
// remoteAddr and path are escaped (sanitizeLogField) so a control byte cannot forge an
// extra line.
func formatAccessLine(start time.Time, remoteAddr string, path string, messageCount int, status string) string {
	elapsedMs := float64(time.Since(start).Microseconds()) / 1000.0

	return fmt.Sprintf(
		"%s %s %s messages=%d %s %.2fms\n",
		start.Format("2006-01-02T15:04:05.000000"),
		sanitizeLogField(remoteAddr),
		sanitizeLogField(path),
		messageCount,
		status,
		elapsedMs,
	)
}

// sanitizeLogField escapes control bytes (C0 range and DEL) as \xNN so a value decoded
// from the request cannot inject a newline and split the log line.
func sanitizeLogField(value string) string {
	if !strings.ContainsFunc(value, func(r rune) bool { return r < 0x20 || r == 0x7F }) {
		return value
	}

	var builder strings.Builder

	for index := 0; index < len(value); index++ {
		char := value[index]

		if char < 0x20 || char == 0x7F {
			fmt.Fprintf(&builder, "\\x%02X", char)
		} else {
			builder.WriteByte(char)
		}
	}

	return builder.String()
}
