package httpserver_feature

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"sconcur/internal/dto"
	"sconcur/internal/features/httpserver/payloads"
	"sconcur/internal/helpers"
	"strconv"
	"strings"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// maxRequestBody caps how much of a request body is read into memory (10 MiB).
const maxRequestBody = 10 << 20

// respondData is the response a PHP handler produced for one request.
type respondData struct {
	status  int
	headers map[string]string
	body    string
}

// serverState is the streaming state of one HTTP server: each accepted request
// is one "batch" pulled by PHP via next(). Implements contracts.StateContract.
type serverState struct {
	ctx       context.Context
	message   *dto.Message
	listener  net.Listener
	requests  chan *payloads.RequestEvent
	startTime time.Time
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

	go state.acceptLoop()

	return state
}

func (s *serverState) acceptLoop() {
	for {
		conn, err := s.listener.Accept()

		if err != nil {
			// Listener closed (server stop) or a fatal accept error: stop looping.
			return
		}

		go s.handleConn(conn)
	}
}

func (s *serverState) handleConn(conn net.Conn) {
	defer func() { _ = conn.Close() }()

	request, err := http.ReadRequest(bufio.NewReader(conn))

	if err != nil {
		return
	}

	requestId := nextRequestId(s.message.FlowKey)
	responseCh := make(chan respondData, 1)

	pendingRequests.Store(requestId, responseCh)
	defer pendingRequests.Delete(requestId)

	event := &payloads.RequestEvent{
		RequestId: requestId,
		Method:    request.Method,
		Path:      request.URL.Path,
		Query:     request.URL.RawQuery,
		Headers:   request.Header,
		Body:      readBody(request),
	}

	// Hand the request to PHP (via Next) and wait for the handler's response.
	select {
	case s.requests <- event:
	case <-s.ctx.Done():
		return
	}

	select {
	case response := <-responseCh:
		writeResponse(conn, response)
	case <-s.ctx.Done():
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

func (s *serverState) Close() {
	_ = s.listener.Close()
}

func readBody(request *http.Request) string {
	if request.Body == nil {
		return ""
	}

	defer func() { _ = request.Body.Close() }()

	data, err := io.ReadAll(io.LimitReader(request.Body, maxRequestBody))

	if err != nil {
		return ""
	}

	return string(data)
}

func writeResponse(conn net.Conn, response respondData) {
	status := response.status

	if status == 0 {
		status = http.StatusOK
	}

	headers := response.headers

	if headers == nil {
		headers = map[string]string{}
	}

	if _, ok := headers["Content-Length"]; !ok {
		headers["Content-Length"] = strconv.Itoa(len(response.body))
	}

	// v1 is a one-request-per-connection server.
	headers["Connection"] = "close"

	var builder strings.Builder

	builder.WriteString(fmt.Sprintf("HTTP/1.1 %d %s\r\n", status, http.StatusText(status)))

	for key, value := range headers {
		builder.WriteString(key + ": " + value + "\r\n")
	}

	builder.WriteString("\r\n")
	builder.WriteString(response.body)

	_, _ = conn.Write([]byte(builder.String()))
}
