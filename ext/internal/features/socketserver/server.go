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
	"sconcur/internal/states"
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

// writeKind tags what an action does to the connection. opFrame writes one
// length-prefixed frame to the client; opClose closes the connection.
type writeKind int

const (
	opFrame writeKind = 0
	opClose writeKind = 1
)

// writeCommand is one action a PHP handler performs on its connection. done carries
// the result of applying it back to the issuing coroutine (nil on success, an error
// if the client write failed) so the handler gets real write backpressure and learns
// about a dead connection.
type writeCommand struct {
	kind writeKind
	data string
	done chan error
}

// pendingConnection is the rendezvous between the connection goroutine (the write
// loop) and the PHP handler's write/close commands. abandoned is closed once the
// write loop stops consuming (server shutdown or the connection is gone) so a handler
// that writes late unblocks with an error instead of hanging on the commands channel.
type pendingConnection struct {
	conn      net.Conn
	commands  chan writeCommand
	abandoned chan struct{}
}

// closeRead closes only the read side of the connection (graceful drain): blocked
// reads return EOF so an idle handler loop ends, while an in-flight response can
// still be written. Falls back to a full close if half-close is unavailable.
func (p *pendingConnection) closeRead() {
	if tcpConn, ok := p.conn.(*net.TCPConn); ok {
		_ = tcpConn.CloseRead()

		return
	}

	_ = p.conn.Close()
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
}

func newServerState(
	ctx context.Context,
	message *dto.Message,
	listener net.Listener,
	startTime time.Time,
	config serverConfig,
) *serverState {
	state := &serverState{
		ctx:         ctx,
		message:     message,
		listener:    listener,
		config:      config,
		startTime:   startTime,
		connections: make(chan *payloads.ConnectionEvent, connectionQueueSize),
		sem:         newSemaphore(config.maxConcurrency),
	}

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

	connectionId := nextConnectionId(s.message.FlowKey)

	pending := &pendingConnection{
		conn:      conn,
		commands:  make(chan writeCommand),
		abandoned: make(chan struct{}),
	}

	pendingConnections.Store(connectionId, pending)
	s.conns.Store(connectionId, pending)

	inboundKey := connectionId + ":in"
	reader := bufio.NewReaderSize(conn, readBufferSize)

	_ = states.Get().Register(inboundKey, newMessageState(s.message, conn, reader, s.config))

	frameCount := 0
	status := "ok"

	defer func() {
		// Once we stop consuming, release a handler still trying to write.
		close(pending.abandoned)
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

	frameCount, status = s.consumeCommands(conn, pending)
}

// consumeCommands applies the handler's actions for one connection (write a frame,
// or close) until the handler closes the connection or the server shuts down. Each
// command's outcome is reported on its done channel so the issuing coroutine gets
// write backpressure and learns when the connection is dead. Returns the number of
// frames written and a status for the access log.
func (s *serverState) consumeCommands(conn net.Conn, pending *pendingConnection) (int, string) {
	frameCount := 0
	status := "ok"

	for {
		select {
		case <-s.ctx.Done():
			return frameCount, "shutdown"
		case command := <-pending.commands:
			if command.kind == opClose {
				command.done <- nil

				return frameCount, status
			}

			err := s.writeFrame(conn, command.data)
			command.done <- err

			if err != nil {
				return frameCount, "write_error"
			}

			frameCount++
		}
	}
}

// writeFrame writes one length-prefixed frame to the client, bounded by writeTimeout.
func (s *serverState) writeFrame(conn net.Conn, data string) error {
	if s.config.writeTimeout > 0 {
		_ = conn.SetWriteDeadline(time.Now().Add(s.config.writeTimeout))
	}

	return writeFrame(conn, []byte(data))
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
		if pending, ok := value.(*pendingConnection); ok {
			pending.closeRead()
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
		if pending, ok := value.(*pendingConnection); ok {
			_ = pending.conn.Close()
		}

		return true
	})
}

// Close shuts the server down: stop accepting and wait for in-flight connections to
// drain (bounded by shutdownTimeout). Run on a fresh context — the task context is
// already cancelled by the time the state is closed.
func (s *serverState) Close() {
	serverStates.Delete(s.message.FlowKey)

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
