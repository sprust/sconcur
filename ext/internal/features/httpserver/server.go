package httpserver_feature

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"sconcur/internal/dto"
	"sconcur/internal/features/httpserver/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/logger"
	"sconcur/internal/states"
	"sconcur/internal/stats"
	"strings"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// Default server tuning, used as a fallback when the PHP side sends a zero value.
// The PHP side normally supplies these (its defaults mirror them).
const (
	defaultMaxRequestBody = 10 << 20 // 10 MiB
	// Fixed transport granularity (not configurable): the inline first-chunk size
	// and the bytes read per streamed chunk. App-level sizing is RequestBody::read.
	defaultRequestBodyChunkSize = 64 << 10 // 64 KiB
	defaultShutdownTimeout      = 5 * time.Second
	defaultReadHeaderTimeout    = 10 * time.Second
	defaultReadTimeout          = 30 * time.Second
	defaultWriteTimeout         = 30 * time.Second
	defaultIdleTimeout          = 60 * time.Second

	// requestQueueSize buffers accepted requests that have been handed off by
	// ServeHTTP but not yet pulled by the PHP serve loop (via next()). It is a
	// smoothing buffer for accept bursts, not the real backpressure: that is
	// maxConcurrency, which caps in-flight ServeHTTP goroutines (and thus buffered
	// bodies) before the body is read. With maxConcurrency unset this bounds how
	// many accepted requests (each holding its inline first chunk) can queue here;
	// beyond it, ServeHTTP blocks on the send until PHP catches up.
	requestQueueSize = 1024
)

// serverConfig holds the resolved tuning for one server instance.
type serverConfig struct {
	readHeaderTimeout time.Duration
	readTimeout       time.Duration
	writeTimeout      time.Duration
	idleTimeout       time.Duration
	shutdownTimeout   time.Duration
	handlerTimeout    time.Duration
	maxRequestBody    int64
	maxConcurrency    int
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
// defaults for any zero (unset) value.
func configFromPayload(payload payloads.ServePayload) serverConfig {
	return serverConfig{
		readHeaderTimeout: msOrDefault(payload.ReadHeaderTimeoutMs, defaultReadHeaderTimeout),
		readTimeout:       msOrDefault(payload.ReadTimeoutMs, defaultReadTimeout),
		writeTimeout:      msOrDefault(payload.WriteTimeoutMs, defaultWriteTimeout),
		idleTimeout:       msOrDefault(payload.IdleTimeoutMs, defaultIdleTimeout),
		shutdownTimeout:   msOrDefault(payload.ShutdownTimeoutMs, defaultShutdownTimeout),
		maxRequestBody:    int64OrDefault(payload.MaxRequestBody, defaultMaxRequestBody),
		// 0 stays 0 (disabled/unlimited); a negative value is treated the same.
		handlerTimeout: time.Duration(max(payload.HandlerTimeoutMs, 0)) * time.Millisecond,
		maxConcurrency: max(payload.MaxConcurrency, 0),
		adminToken:     payload.AdminToken,
		statsDir:       payload.StatsDir,
		serverName:     payload.ServerName,
		statsPort:      payload.StatsPort,
	}
}

func msOrDefault(ms int, fallback time.Duration) time.Duration {
	if ms <= 0 {
		return fallback
	}

	return time.Duration(ms) * time.Millisecond
}

func int64OrDefault(value int64, fallback int64) int64 {
	if value <= 0 {
		return fallback
	}

	return value
}

// writeKind tags what a write command does to the connection. A one-shot
// response is a single writeFull; a streamed response is writeHead, then any
// number of writeChunk, then writeEnd.
type writeKind int

const (
	writeFull  writeKind = 0 // status + headers + body, then finish
	writeHead  writeKind = 1 // status + headers, flushed (start of a stream)
	writeChunk writeKind = 2 // body bytes, flushed
	writeEnd   writeKind = 3 // finish a stream
)

// writeCommand is one instruction the PHP handler sends for a request. done
// carries the result of applying it back to the issuing coroutine (nil on
// success, an error if the client write failed) so the handler gets real write
// backpressure and stops early on a dead connection.
type writeCommand struct {
	kind    writeKind
	status  int
	headers map[string][]string
	body    string
	done    chan error
}

// pendingRequest is the rendezvous between the connection goroutine (ServeHTTP)
// and the PHP handler's write commands. abandoned is closed once ServeHTTP stops
// consuming — on a handler timeout or any return — so a handler that responds
// late unblocks with an error instead of hanging on the unbuffered commands chan.
type pendingRequest struct {
	commands  chan writeCommand
	abandoned chan struct{}
}

// serverState is the streaming state of one HTTP server: each accepted request
// is one "batch" pulled by PHP via next(). Implements contracts.StateContract.
//
// The network is handled by a standard net/http.Server (keep-alive, timeouts,
// correct parsing/writing); serverState is its http.Handler. Each request is
// handed to PHP through the requests channel and the handler goroutine blocks on
// a per-request response channel until the PHP coroutine answers.
type serverState struct {
	ctx        context.Context
	message    *dto.Message
	listener   net.Listener
	httpServer *http.Server
	requests   chan *payloads.RequestEvent
	startTime  time.Time
	config     serverConfig
	// sem bounds concurrent in-flight requests when maxConcurrency > 0; nil means
	// unlimited. Acquired before the body is read, released when ServeHTTP returns,
	// so it caps goroutines, buffered bodies and (transitively) PHP coroutines.
	sem chan struct{}
	// requestStats holds this worker's request counters (the stats workload);
	// collector writes its snapshot file; statsServer is the optional dedicated
	// stats endpoint (nil when no stats port/token is configured).
	requestStats *requestStats
	collector    *stats.Collector
	statsServer  *stats.Server
}

func newServerState(
	ctx context.Context,
	message *dto.Message,
	listener net.Listener,
	startTime time.Time,
	config serverConfig,
) *serverState {
	requestStats := &requestStats{}

	state := &serverState{
		ctx:          ctx,
		message:      message,
		listener:     listener,
		requests:     make(chan *payloads.RequestEvent, requestQueueSize),
		startTime:    startTime,
		config:       config,
		sem:          newSemaphore(config.maxConcurrency),
		requestStats: requestStats,
		collector:    stats.NewCollector(config.serverName, config.statsDir, startTime, requestStats),
	}

	state.collector.Start()
	state.statsServer = stats.MaybeStartServer(config.statsPort, config.adminToken, config.statsDir, config.serverName)

	state.httpServer = &http.Server{
		Handler:           state,
		ReadHeaderTimeout: config.readHeaderTimeout,
		ReadTimeout:       config.readTimeout,
		WriteTimeout:      config.writeTimeout,
		IdleTimeout:       config.idleTimeout,
		// Tie every request context to the server's lifetime so blocked handlers
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

// ServeHTTP handles one request: hand it to PHP, wait for the response.
func (s *serverState) ServeHTTP(writer http.ResponseWriter, request *http.Request) {
	// The access log is written here on the Go side (from this handler goroutine),
	// not in PHP: it avoids one PHP->Go cgo crossing per request — the dominant cost
	// of a tiny request — and covers every outcome, including the 503/504/abandoned
	// cases PHP never sees. status is set as the response is produced; the deferred
	// write logs it for every return path (early errors included).
	start := time.Now()
	status := 0

	defer func() {
		logger.Write(formatAccessLine(start, request.Method, request.URL.Path, status))
	}()

	// Bound concurrency before touching the body, so requests waiting for a slot
	// hold no body buffer: this caps memory (and goroutines) under load. A waiting
	// request unblocks when a slot frees or the server stops.
	if s.sem != nil {
		select {
		case s.sem <- struct{}{}:
			defer func() { <-s.sem }()
		case <-s.ctx.Done():
			// Shutting down before this request got a slot: answer 503 instead of
			// resetting the connection.
			status = http.StatusServiceUnavailable
			writeServiceUnavailable(writer)

			return
		}
	}

	requestId := nextRequestId(s.message.FlowKey)

	// Track the request for the statistics: count it and its duration on return,
	// and keep it in the in-flight set (with its start) for the age buckets.
	s.requestStats.requestBegan(requestId, start)
	defer s.requestStats.requestEnded(requestId, start)

	// Read only the first chunk inline (bounded by maxRequestBody): small bodies
	// arrive whole with the event (no extra round-trips), large ones stream the
	// remainder on demand via a bodyState — the body is never buffered whole.
	reader := http.MaxBytesReader(writer, request.Body, s.config.maxRequestBody)

	firstChunk, bodyComplete, err := helpers.ReadChunk(reader, defaultRequestBodyChunkSize)

	if err != nil {
		var maxBytesError *http.MaxBytesError

		if errors.As(err, &maxBytesError) {
			status = http.StatusRequestEntityTooLarge
			http.Error(writer, "request body too large", http.StatusRequestEntityTooLarge)
		} else {
			status = http.StatusBadRequest
			http.Error(writer, "bad request body", http.StatusBadRequest)
		}

		return
	}

	bodyKey := ""

	if !bodyComplete {
		bodyKey = requestId + ":body"

		_ = states.Get().Register(bodyKey, newBodyState(s.message, reader, defaultRequestBodyChunkSize))

		defer states.Get().DeleteState(bodyKey)
	}

	pending := &pendingRequest{
		commands:  make(chan writeCommand),
		abandoned: make(chan struct{}),
	}

	pendingRequests.Store(requestId, pending)
	defer pendingRequests.Delete(requestId)
	// Once we stop consuming, release a handler still trying to respond.
	defer close(pending.abandoned)

	event := &payloads.RequestEvent{
		RequestId:  requestId,
		Method:     request.Method,
		Path:       request.URL.Path,
		Query:      request.URL.RawQuery,
		Headers:    request.Header,
		Body:       string(firstChunk),
		BodyKey:    bodyKey,
		RemoteAddr: request.RemoteAddr,
		Host:       request.Host,
		Proto:      request.Proto,
	}

	// Deliver the request to PHP and wait for the handler's response. We wait on
	// the server context, not the per-request one: the PHP handler coroutine must
	// always complete its round-trip (so its flow is cleaned up), and net/http may
	// cancel the request context independently. If the client has gone, the final
	// write simply no-ops.
	select {
	case s.requests <- event:
	case <-s.ctx.Done():
		// Shutting down before PHP accepted this request: answer 503.
		status = http.StatusServiceUnavailable
		writeServiceUnavailable(writer)

		return
	}

	status = s.consumeCommands(writer, pending.commands)
}

// consumeCommands applies the handler's write commands in order until the
// response is finished (writeFull/writeEnd) or the server is shutting down. Each
// command's outcome is reported on its done channel so the issuing coroutine
// gets write backpressure. Returns the status sent to the client (for the access
// log): the one from the full/head command, or 503/504 on a drain/timeout abort.
func (s *serverState) consumeCommands(writer http.ResponseWriter, commands chan writeCommand) int {
	// Flusher lets a streamed response push each chunk to the client immediately
	// (chunked transfer); absent only on exotic ResponseWriters.
	flusher, _ := writer.(http.Flusher)

	// started becomes true once any bytes/headers have gone out, after which the
	// status can no longer change (so no 503 fallback on shutdown). status records
	// what was sent, for the access log.
	started := false
	status := 0

	// Bound the total handling time: the whole request — including a streamed
	// response — must complete within handlerTimeout, otherwise it is cut off.
	// A stuck handler must not hold the connection (and its concurrency slot)
	// forever. Disabled when handlerTimeout is 0.
	var timeout <-chan time.Time

	if s.config.handlerTimeout > 0 {
		timer := time.NewTimer(s.config.handlerTimeout)
		defer timer.Stop()

		timeout = timer.C
	}

	for {
		select {
		case <-s.ctx.Done():
			// The server is shutting down. A command may be ready at the same time
			// (the handler answered just as the flow stopped); Go picks a ready case
			// at random, so drain one pending command non-blocking and still write
			// what the handler produced instead of dropping it.
			select {
			case command := <-commands:
				finished, err := applyWrite(writer, flusher, command)
				command.done <- err

				if command.kind != writeEnd {
					started = true
				}

				if command.kind == writeFull || command.kind == writeHead {
					status = normalizeStatus(command.status)
				}

				if finished {
					return status
				}
			default:
			}

			// A request delivered to PHP but never answered (refused during drain):
			// answer 503 rather than reset, as long as nothing was written yet.
			if !started {
				writeServiceUnavailable(writer)

				return http.StatusServiceUnavailable
			}

			return status
		case <-timeout:
			// The total deadline elapsed. Before the first write we can still
			// answer 504; once a response has started the status is already on the
			// wire, so just abort the (now-truncated) stream and free the slot.
			if !started {
				writeGatewayTimeout(writer)

				return http.StatusGatewayTimeout
			}

			return status
		case command := <-commands:
			finished, err := applyWrite(writer, flusher, command)
			command.done <- err

			if command.kind != writeEnd {
				started = true
			}

			if command.kind == writeFull || command.kind == writeHead {
				status = normalizeStatus(command.status)
			}

			if finished {
				return status
			}
		}
	}
}

func (s *serverState) Next() *dto.Result {
	select {
	case event := <-s.requests:
		serialized, err := msgpack.Marshal(event)

		if err != nil {
			return dto.NewErrorResult(s.message, "httpServer: marshal request: "+err.Error())
		}

		return dto.NewSuccessResultWithNext(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
	case <-s.ctx.Done():
		// Server stopped: end the stream so the PHP serve loop exits.
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}
}

// stopAccepting closes the listener (and stops accepting) without cancelling
// in-flight requests, so a SO_REUSEPORT sibling takes over new connections while
// this server drains. Shutdown runs in its own goroutine on a background context:
// it closes the listener immediately and returns once active handlers finish.
func (s *serverState) stopAccepting() {
	go func() {
		_ = s.httpServer.Shutdown(context.Background())
	}()
}

// Close gracefully shuts the server down: stop accepting and wait for in-flight
// requests to drain. Run on a fresh context — the task context is already
// cancelled by the time the state is closed.
func (s *serverState) Close() {
	serverStates.Delete(s.message.FlowKey)

	s.collector.Stop()
	s.statsServer.Close()

	ctx, cancel := context.WithTimeout(context.Background(), s.config.shutdownTimeout)
	defer cancel()

	_ = s.httpServer.Shutdown(ctx)
}

// applyWrite carries out one write command against the connection and reports
// whether it finishes the response and whether the client write failed.
func applyWrite(writer http.ResponseWriter, flusher http.Flusher, command writeCommand) (finished bool, err error) {
	switch command.kind {
	case writeFull:
		writeHeaders(writer, command)
		writeStatus(writer, command.status)
		_, err = io.WriteString(writer, command.body)

		return true, err
	case writeHead:
		writeHeaders(writer, command)
		writeStatus(writer, command.status)
		flush(flusher)

		return false, nil
	case writeChunk:
		_, err = io.WriteString(writer, command.body)
		flush(flusher)

		return false, err
	case writeEnd:
		return true, nil
	default:
		return true, nil
	}
}

func writeHeaders(writer http.ResponseWriter, command writeCommand) {
	header := writer.Header()

	for key, values := range command.headers {
		for _, value := range values {
			header.Add(key, value)
		}
	}
}

// writeServiceUnavailable answers a request the server can no longer handle
// (shutting down) with a 503, used in place of dropping the connection. Safe only
// before any other write, since it calls WriteHeader.
func writeServiceUnavailable(writer http.ResponseWriter) {
	writer.WriteHeader(http.StatusServiceUnavailable)

	_, _ = io.WriteString(writer, "Service Unavailable")
}

// writeGatewayTimeout answers a request whose handler did not respond within the
// configured deadline with a 504. Safe only before any other write.
func writeGatewayTimeout(writer http.ResponseWriter) {
	writer.WriteHeader(http.StatusGatewayTimeout)

	_, _ = io.WriteString(writer, "Gateway Timeout")
}

func writeStatus(writer http.ResponseWriter, status int) {
	writer.WriteHeader(normalizeStatus(status))
}

// normalizeStatus maps the "unset" 0 to 200, matching net/http's WriteHeader default.
func normalizeStatus(status int) int {
	if status == 0 {
		return http.StatusOK
	}

	return status
}

func flush(flusher http.Flusher) {
	if flusher != nil {
		flusher.Flush()
	}
}

// formatAccessLine builds one access-log line for a finished request:
//
//	<ISO-start-time> <method> <path> <status> <ms>ms
//
// matching the format PHP used before logging moved to the Go side. Method and
// path are escaped (sanitizeLogField) so a control byte decoded from the URL
// cannot forge an extra line.
func formatAccessLine(start time.Time, method string, path string, status int) string {
	elapsedMs := float64(time.Since(start).Microseconds()) / 1000.0

	return fmt.Sprintf(
		"%s %s %s %d %.2fms\n",
		start.Format("2006-01-02T15:04:05.000000"),
		sanitizeLogField(method),
		sanitizeLogField(path),
		status,
		elapsedMs,
	)
}

// sanitizeLogField escapes control bytes (C0 range and DEL) as \xNN so a value
// decoded from the request URL cannot inject a newline and split the log line.
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
