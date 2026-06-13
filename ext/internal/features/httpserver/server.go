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

// maxRequestBody caps how much of a request body is read into memory (10 MiB).
const maxRequestBody = 10 << 20

// shutdownTimeout bounds how long Close waits for in-flight requests to drain.
const shutdownTimeout = 5 * time.Second

// Connection timeouts for the embedded net/http server.
const (
	readHeaderTimeout = 10 * time.Second
	readTimeout       = 30 * time.Second
	writeTimeout      = 30 * time.Second
	idleTimeout       = 60 * time.Second
)

// respondData is the response a PHP handler produced for one request.
type respondData struct {
	status  int
	headers map[string]string
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
}

func newServerState(
	ctx context.Context,
	message *dto.Message,
	listener net.Listener,
	startTime time.Time,
) *serverState {
	state := &serverState{
		ctx:       ctx,
		message:   message,
		listener:  listener,
		requests:  make(chan *payloads.RequestEvent, 1024),
		startTime: startTime,
	}

	state.httpServer = &http.Server{
		Handler:           state,
		ReadHeaderTimeout: readHeaderTimeout,
		ReadTimeout:       readTimeout,
		WriteTimeout:      writeTimeout,
		IdleTimeout:       idleTimeout,
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
	requestId := nextRequestId(s.message.FlowKey)
	responseCh := make(chan respondData, 1)

	pendingRequests.Store(requestId, responseCh)
	defer pendingRequests.Delete(requestId)

	body, _ := io.ReadAll(io.LimitReader(request.Body, maxRequestBody))

	event := &payloads.RequestEvent{
		RequestId: requestId,
		Method:    request.Method,
		Path:      request.URL.Path,
		Query:     request.URL.RawQuery,
		Headers:   request.Header,
		Body:      string(body),
	}

	// Deliver the request to PHP (via Next).
	select {
	case s.requests <- event:
	case <-request.Context().Done():
		return
	}

	// Wait for the PHP handler's response.
	select {
	case response := <-responseCh:
		writeResponse(writer, response)
	case <-request.Context().Done():
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
	ctx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
	defer cancel()

	_ = s.httpServer.Shutdown(ctx)
}

func writeResponse(writer http.ResponseWriter, response respondData) {
	for key, value := range response.headers {
		writer.Header().Set(key, value)
	}

	status := response.status

	if status == 0 {
		status = http.StatusOK
	}

	writer.WriteHeader(status)

	_, _ = io.WriteString(writer, response.body)
}
