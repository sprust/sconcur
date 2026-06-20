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
	handlerTimeout  time.Duration
	shutdownTimeout time.Duration
	maxMessageBytes int
	maxConcurrency  int
}

// configFromPayload resolves the tuning from the PHP payload, falling back to the
// defaults for any zero (unset) value where a default makes sense. readTimeout and
// handlerTimeout keep 0 as "disabled".
func configFromPayload(payload payloads.ServePayload) serverConfig {
	return serverConfig{
		readTimeout:     time.Duration(max(payload.ReadTimeoutMs, 0)) * time.Millisecond,
		writeTimeout:    msOrDefault(payload.WriteTimeoutMs, defaultWriteTimeout),
		handlerTimeout:  time.Duration(max(payload.HandlerTimeoutMs, 0)) * time.Millisecond,
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

// writeKind tags what a write command does. writeFrame writes one length-prefixed
// frame; writeSkip writes nothing (a no-reply acknowledgement that just disarms the
// per-message handler timer).
type writeKind int

const (
	opFrame writeKind = 0
	opSkip  writeKind = 1
)

// writeCommand is one instruction a PHP handler sends for a message. done carries
// the result of applying it back to the issuing coroutine (nil on success, an error
// if the client write failed) so the handler gets real write backpressure and stops
// early on a dead connection. close additionally closes the connection afterwards.
type writeCommand struct {
	kind  writeKind
	close bool
	data  string
	done  chan error
}

// pendingConnection is the rendezvous between the connection goroutine (the write
// loop) and the PHP handler's write commands. abandoned is closed once the write
// loop stops consuming (handler timeout, server shutdown, or the connection is
// gone) so a handler that responds late unblocks with an error instead of hanging.
// messageStarted (capacity 1) is signaled by the inbound reader each time a frame
// is delivered to PHP, so the write loop can arm the per-message handler timer.
type pendingConnection struct {
	conn           net.Conn
	commands       chan writeCommand
	abandoned      chan struct{}
	messageStarted chan struct{}
}

func (p *pendingConnection) signalMessageStarted() {
	select {
	case p.messageStarted <- struct{}{}:
	default:
	}
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
		conn:           conn,
		commands:       make(chan writeCommand),
		abandoned:      make(chan struct{}),
		messageStarted: make(chan struct{}, 1),
	}

	pendingConnections.Store(connectionId, pending)
	s.conns.Store(connectionId, pending)

	inboundKey := connectionId + ":in"
	reader := bufio.NewReaderSize(conn, readBufferSize)

	_ = states.Get().Register(inboundKey, newMessageState(s.message, conn, reader, pending, s.config))

	messageCount := 0
	status := "ok"

	defer func() {
		// Once we stop consuming, release a handler still trying to respond.
		close(pending.abandoned)
		pendingConnections.Delete(connectionId)
		s.conns.Delete(connectionId)
		states.Get().DeleteState(inboundKey)
		_ = conn.Close()

		logger.Write(formatAccessLine(start, remoteAddr, messageCount, status))
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

	messageCount, status = s.consumeCommands(conn, pending)
}

// consumeCommands applies the handler's write commands for one connection until it
// closes (a command with close set), the per-message handler timeout fires, or the
// server shuts down. Each command's outcome is reported on its done channel so the
// issuing coroutine gets write backpressure. The handler timer is armed when the
// inbound reader signals a message went into handling and disarmed when its response
// (or no-reply ack) arrives — so it bounds the time to handle a single message,
// independently of PHP. Returns the message count and a status for the access log.
func (s *serverState) consumeCommands(conn net.Conn, pending *pendingConnection) (int, string) {
	messageCount := 0
	status := "ok"

	var timer *time.Timer
	var timeout <-chan time.Time

	armTimer := func() {
		if s.config.handlerTimeout <= 0 {
			return
		}

		timer = time.NewTimer(s.config.handlerTimeout)
		timeout = timer.C
	}

	disarmTimer := func() {
		if timer != nil {
			timer.Stop()
			timer = nil
		}

		timeout = nil
	}

	defer disarmTimer()

	for {
		select {
		case <-s.ctx.Done():
			return messageCount, "shutdown"
		case <-pending.messageStarted:
			messageCount++
			armTimer()
		case <-timeout:
			// The handler did not respond in time (a slow async handler or a natively
			// blocked PHP thread): cut the connection off. The deferred cleanup closes
			// the connection and the abandoned channel, so a late response unblocks.
			return messageCount, "handler_timeout"
		case command := <-pending.commands:
			disarmTimer()

			err := s.applyCommand(conn, command)
			command.done <- err

			if err != nil {
				return messageCount, "write_error"
			}

			if command.close {
				return messageCount, status
			}
		}
	}
}

// applyCommand carries out one write command against the connection.
func (s *serverState) applyCommand(conn net.Conn, command writeCommand) error {
	if command.kind == opSkip {
		return nil
	}

	if s.config.writeTimeout > 0 {
		_ = conn.SetWriteDeadline(time.Now().Add(s.config.writeTimeout))
	}

	return writeFrame(conn, []byte(command.data))
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
// connections) and half-closes the read side of every in-flight connection, so each
// idle handler loop ends on EOF while a current response can still be written. This
// is what lets the generic serve loop drain long-lived connections on shutdown.
func (s *serverState) stopAccepting() {
	_ = s.listener.Close()

	s.conns.Range(func(_, value any) bool {
		if pending, ok := value.(*pendingConnection); ok {
			pending.closeRead()
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
//	<ISO-start-time> <remoteAddr> msgs=<n> <status> <ms>ms
func formatAccessLine(start time.Time, remoteAddr string, messageCount int, status string) string {
	elapsedMs := float64(time.Since(start).Microseconds()) / 1000.0

	return fmt.Sprintf(
		"%s %s msgs=%d %s %.2fms\n",
		start.Format("2006-01-02T15:04:05.000000"),
		remoteAddr,
		messageCount,
		status,
		elapsedMs,
	)
}
