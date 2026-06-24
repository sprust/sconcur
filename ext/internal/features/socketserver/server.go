package socketserver_feature

import (
	"bufio"
	"context"
	"fmt"
	"net"
	"sconcur/internal/dto"
	"sconcur/internal/features/socketserver/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/logger"
	"sconcur/internal/socket"
	"sconcur/internal/states"
	"sconcur/internal/stats"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// Default server tuning, used as a fallback when the PHP side sends a zero value.
// The PHP side normally supplies these (its defaults mirror them).
const (
	defaultWriteTimeout    = 30 * time.Second
	defaultShutdownTimeout = 5 * time.Second
	defaultMaxMessageBytes = 1 << 20 // 1 MiB
	// drainGrace bounds how long a connection may keep its handler alive after the
	// listener stops accepting; past it the connection is force-closed so a handler
	// that never reads (push-only) still unwinds and the server can finish draining.
	drainGrace = 2 * time.Second

	// readBufferSize is the size of the bufio.Reader wrapping each connection.
	readBufferSize = 64 << 10 // 64 KiB

	// connectionQueueSize buffers accepted connections that the accept loop has
	// handed off but the PHP serve loop has not yet pulled via next(). It smooths
	// accept bursts; the real backpressure is maxConcurrency.
	connectionQueueSize = 1024
)

// serverConfig holds the resolved tuning for one server instance.
type serverConfig struct {
	readTimeout     time.Duration
	writeTimeout    time.Duration
	shutdownTimeout time.Duration
	maxMessageBytes int
	maxConcurrency  int
	// adminToken gates the dedicated stats server (empty = off); statsDir and
	// serverName locate and scope the per-worker snapshot files; statsPort is the
	// port the stats server binds (0 = off). The stats server runs only when both a
	// port and a token are set.
	adminToken string
	statsDir   string
	serverName string
	statsPort  int
}

// configFromPayload resolves the tuning from the PHP payload, falling back to the
// defaults for any zero (unset) value where a default makes sense. readTimeout keeps
// 0 as "disabled".
func configFromPayload(payload payloads.ServePayload) serverConfig {
	return serverConfig{
		readTimeout:     time.Duration(max(payload.ReadTimeoutMs, 0)) * time.Millisecond,
		writeTimeout:    msOrDefault(payload.WriteTimeoutMs, defaultWriteTimeout),
		shutdownTimeout: msOrDefault(payload.ShutdownTimeoutMs, defaultShutdownTimeout),
		maxMessageBytes: intOrDefault(payload.MaxMessageBytes, defaultMaxMessageBytes),
		maxConcurrency:  max(payload.MaxConcurrency, 0),
		adminToken:      payload.AdminToken,
		statsDir:        payload.StatsDir,
		serverName:      payload.ServerName,
		statsPort:       payload.StatsPort,
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

// serverState is the streaming state of one socket server: each accepted connection
// is one "batch" pulled by PHP via next(). Implements contracts.StateContract. The
// accept loop runs a goroutine per connection (handleConn); the inbound frames of a
// connection are a separate streaming state (messageState).
type serverState struct {
	ctx         context.Context
	message     *dto.Message
	listener    net.Listener
	config      serverConfig
	startTime   time.Time
	connections chan *payloads.ConnectionEvent
	// sem bounds concurrent in-flight connections when maxConcurrency > 0; nil means
	// unlimited.
	sem chan struct{}
	// conns tracks this server's live connections (connId → *pendingConnection) so
	// stopAccepting can half-close them all on graceful drain.
	conns sync.Map
	// waitGroup tracks live handleConn goroutines so Close can drain them.
	waitGroup sync.WaitGroup
	// connectionStats holds this worker's connection counters (the stats workload);
	// collector writes its snapshot file; statsServer is the optional dedicated
	// stats endpoint (nil when no stats port/token is configured).
	connectionStats *connectionStats
	collector       *stats.Collector
	statsServer     *stats.Server
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
		collector:       stats.NewCollector(config.serverName, config.statsDir, startTime, connectionStats),
	}

	state.collector.Start()
	state.statsServer = stats.MaybeStartServer(config.statsPort, config.adminToken, config.statsDir, config.serverName)

	go state.acceptLoop()

	return state
}

// newSemaphore builds the concurrency limiter, or nil when unlimited (size <= 0).
func newSemaphore(size int) chan struct{} {
	if size <= 0 {
		return nil
	}

	return make(chan struct{}, size)
}

func (s *serverState) acceptLoop() {
	for {
		conn, err := s.listener.Accept()

		if err != nil {
			// The listener was closed (stopAccepting / Close): stop accepting.
			return
		}

		s.waitGroup.Add(1)

		go func() {
			defer s.waitGroup.Done()

			s.handleConn(conn)
		}()
	}
}

// handleConn serves one connection: hand it to PHP as a ConnectionEvent, then run
// the write loop applying the handler's responses until the connection closes.
func (s *serverState) handleConn(conn net.Conn) {
	start := time.Now()
	remoteAddr := conn.RemoteAddr().String()

	// Bound concurrency before doing anything else, so a waiting connection holds no
	// reader buffer. A waiting connection unblocks when a slot frees or the server
	// stops.
	if s.sem != nil {
		select {
		case s.sem <- struct{}{}:
			defer func() { <-s.sem }()
		case <-s.ctx.Done():
			_ = conn.Close()

			return
		}
	}

	// Count this connection for the statistics (active + lifetime total) once it has
	// a concurrency slot, releasing the active count when it finishes.
	s.connectionStats.connectionOpened()
	defer s.connectionStats.connectionClosed()

	connectionId := socket.NextConnectionId(s.message.FlowKey)

	pending := &socket.PendingConnection{
		Conn:      conn,
		Commands:  make(chan socket.WriteCommand),
		Abandoned: make(chan struct{}),
	}

	pendingConnections.Store(connectionId, pending)
	s.conns.Store(connectionId, pending)

	inboundKey := connectionId + ":in"
	reader := bufio.NewReaderSize(conn, readBufferSize)

	_ = states.Get().Register(inboundKey, socket.NewMessageState(
		s.message,
		conn,
		reader,
		s.config.readTimeout,
		s.config.maxMessageBytes,
		errFactory,
	))

	frameCount := 0
	status := "ok"

	defer func() {
		// Once we stop consuming, release a handler still trying to write.
		close(pending.Abandoned)
		pendingConnections.Delete(connectionId)
		s.conns.Delete(connectionId)
		states.Get().DeleteState(inboundKey)
		_ = conn.Close()

		logger.Write(formatAccessLine(start, remoteAddr, frameCount, status))
	}()

	event := &payloads.ConnectionEvent{
		ConnectionId: connectionId,
		RemoteAddr:   remoteAddr,
		LocalAddr:    conn.LocalAddr().String(),
	}

	select {
	case s.connections <- event:
	case <-s.ctx.Done():
		status = "shutdown"

		return
	}

	frameCount, status = socket.ConsumeCommands(s.ctx, pending, s.config.writeTimeout)
}

func (s *serverState) Next() *dto.Result {
	select {
	case event := <-s.connections:
		serialized, err := msgpack.Marshal(event)

		if err != nil {
			return dto.NewErrorResult(s.message, "socketServer: marshal connection: "+err.Error())
		}

		return dto.NewSuccessResultWithNext(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
	case <-s.ctx.Done():
		// Server stopped: end the stream so the PHP serve loop exits.
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}
}

// stopAccepting closes the listener (so a SO_REUSEPORT sibling takes over new
// connections) and half-closes the read side of every in-flight connection, so a
// handler reading in a loop ends on EOF while it can still write a final frame. A
// push-only handler that never reads would not notice the half-close, so after a
// bounded grace every connection is force-closed too — its next write fails and the
// handler unwinds, letting the generic serve loop finish draining.
func (s *serverState) stopAccepting() {
	_ = s.listener.Close()

	s.conns.Range(func(_, value any) bool {
		if pending, ok := value.(*socket.PendingConnection); ok {
			pending.CloseRead()
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
		if pending, ok := value.(*socket.PendingConnection); ok {
			_ = pending.Conn.Close()
		}

		return true
	})
}

// Close shuts the server down: stop accepting and wait for in-flight connections to
// drain (bounded by shutdownTimeout). Run on a fresh context — the task context is
// already cancelled by the time the state is closed.
func (s *serverState) Close() {
	serverStates.Delete(s.message.FlowKey)

	s.collector.Stop()
	s.statsServer.Close()

	_ = s.listener.Close()

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
//	<ISO-start-time> <remoteAddr> frames=<n> <status> <ms>ms
//
// where frames is the number of frames written to the client over the connection.
func formatAccessLine(start time.Time, remoteAddr string, frameCount int, status string) string {
	elapsedMs := float64(time.Since(start).Microseconds()) / 1000.0

	return fmt.Sprintf(
		"%s %s frames=%d %s %.2fms\n",
		start.Format("2006-01-02T15:04:05.000000"),
		remoteAddr,
		frameCount,
		status,
		elapsedMs,
	)
}
