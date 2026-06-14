package httpserver_feature

import (
	"context"
	"io"
	"net"
	"net/http"
	"sconcur/internal/dto"
	"sconcur/internal/features/httpserver/payloads"
	"sconcur/internal/helpers"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// Default server tuning, used as a fallback when the PHP side sends a zero value.
// The PHP side normally supplies these (its defaults mirror them).
const (
	defaultMaxRequestBody    = 10 << 20 // 10 MiB
	defaultShutdownTimeout   = 5 * time.Second
	defaultReadHeaderTimeout = 10 * time.Second
	defaultReadTimeout       = 30 * time.Second
	defaultWriteTimeout      = 30 * time.Second
	defaultIdleTimeout       = 60 * time.Second
)

// serverConfig holds the resolved tuning for one server instance.
type serverConfig struct {
	readHeaderTimeout time.Duration
	readTimeout       time.Duration
	writeTimeout      time.Duration
	idleTimeout       time.Duration
	shutdownTimeout   time.Duration
	maxRequestBody    int64
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

// respondData is the response a PHP handler produced for one request.
type respondData struct {
	status  int
	headers map[string][]string
	body    string
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
}

func newServerState(
	ctx context.Context,
	message *dto.Message,
	listener net.Listener,
	startTime time.Time,
	config serverConfig,
) *serverState {
	state := &serverState{
		ctx:       ctx,
		message:   message,
		listener:  listener,
		requests:  make(chan *payloads.RequestEvent, 1024),
		startTime: startTime,
		config:    config,
	}

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

// ServeHTTP handles one request: hand it to PHP, wait for the response.
func (s *serverState) ServeHTTP(writer http.ResponseWriter, request *http.Request) {
	// Reject a body larger than the configured limit instead of silently
	// truncating it (which would also leave the connection unusable).
	body, err := io.ReadAll(http.MaxBytesReader(writer, request.Body, s.config.maxRequestBody))

	if err != nil {
		http.Error(writer, "request body too large", http.StatusRequestEntityTooLarge)

		return
	}

	requestId := nextRequestId(s.message.FlowKey)
	responseCh := make(chan respondData, 1)

	pendingRequests.Store(requestId, responseCh)
	defer pendingRequests.Delete(requestId)

	event := &payloads.RequestEvent{
		RequestId:  requestId,
		Method:     request.Method,
		Path:       request.URL.Path,
		Query:      request.URL.RawQuery,
		Headers:    request.Header,
		Body:       string(body),
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
		return
	}

	select {
	case response := <-responseCh:
		writeResponse(writer, response)
	case <-s.ctx.Done():
		// The server is shutting down. Both this and responseCh may be ready at
		// once (the PHP handler answered just as the flow was stopped); Go picks a
		// ready case at random, so re-check responseCh non-blocking and still write
		// a response the handler already produced instead of dropping it.
		select {
		case response := <-responseCh:
			writeResponse(writer, response)
		default:
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

// Close gracefully shuts the server down: stop accepting and wait for in-flight
// requests to drain. Run on a fresh context — the task context is already
// cancelled by the time the state is closed.
func (s *serverState) Close() {
	ctx, cancel := context.WithTimeout(context.Background(), s.config.shutdownTimeout)
	defer cancel()

	_ = s.httpServer.Shutdown(ctx)
}

func writeResponse(writer http.ResponseWriter, response respondData) {
	header := writer.Header()

	for key, values := range response.headers {
		for _, value := range values {
			header.Add(key, value)
		}
	}

	status := response.status

	if status == 0 {
		status = http.StatusOK
	}

	writer.WriteHeader(status)

	_, _ = io.WriteString(writer, response.body)
}
