package httpclient_feature

import (
	"context"
	"io"
	"net/http"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/httpclient/payloads"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"strings"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*HttpClientFeature)(nil)

var once sync.Once
var instance *HttpClientFeature

var errFactory = errs.NewErrorsFactory("httpClient")

// Error-class markers prefixed onto an error payload so the PHP side can map it to
// the right PSR-18 exception. A request that never left (bad URL/method) is a
// request error; everything network-level (connect/DNS/timeout/redirect/read) is a
// network error. The default (no marker) is a generic client error.
const (
	networkErrorMarker = "net"
	requestErrorMarker = "req"
)

// HttpClientFeature handles httpRequest commands: it builds the *http.Request,
// applies the hard execution deadline and registers a streaming responseState
// that performs the request and streams the response body to PHP. Singleton.
type HttpClientFeature struct{}

func Get() *HttpClientFeature {
	once.Do(func() {
		instance = &HttpClientFeature{}
	})

	return instance
}

func (f *HttpClientFeature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var envelope payloads.Envelope

	if err := msgpack.Unmarshal(message.Payload, &envelope); err != nil {
		task.AddResult(dto.NewErrorResult(message, requestErrorPayload(errFactory.ByErr("parse envelope", err))))

		return
	}

	switch types.HttpClientCommand(envelope.Command) {
	case types.HttpClientRequest:
		f.handleRequest(task, envelope.Params)
	case types.HttpClientUploadChunk:
		f.handleUpload(task, envelope.Params, false)
	case types.HttpClientUploadEnd:
		f.handleUpload(task, envelope.Params, true)
	default:
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown command")))
	}
}

// handleRequest opens one HTTP request. With a buffered body it registers the
// streaming responseState that performs the request and streams the response back.
// With a streamed body it pipes the body from PHP upload commands (see upload.go).
func (f *HttpClientFeature) handleRequest(task *tasks.Task, raw msgpack.RawMessage) {
	message := task.GetMessage()

	var payload payloads.RequestParams

	if err := msgpack.Unmarshal(raw, &payload); err != nil {
		task.AddResult(dto.NewErrorResult(message, requestErrorPayload(errFactory.ByErr("parse request params", err))))

		return
	}

	// A hard limit on the whole operation (connect + send + reading the entire
	// body), as required of every feature. Derived from the task context so a flow
	// stop still cancels it. 0 disables the extra deadline (task context only).
	ctx := task.GetContext()

	if payload.RequestTimeoutMs > 0 {
		var cancel context.CancelFunc

		ctx, cancel = context.WithTimeout(ctx, time.Duration(payload.RequestTimeoutMs)*time.Millisecond)

		// The state outlives Handle (it is pulled via next), so the cancel must live
		// until the state is closed. Tie it to context cancellation instead of
		// leaking it: AfterFunc fires when ctx is done (deadline, flow stop, parent).
		context.AfterFunc(ctx, cancel)
	}

	// A streamed body is a pipe filled by upload commands; a buffered body is read
	// from the payload in one shot.
	var bodyReader io.Reader
	var pipeWriter *io.PipeWriter

	if payload.StreamBody {
		pipeReader, writer := io.Pipe()
		bodyReader = pipeReader
		pipeWriter = writer
	} else {
		bodyReader = strings.NewReader(payload.Body)
	}

	request, err := http.NewRequestWithContext(ctx, payload.Method, payload.Url, bodyReader)

	if err != nil {
		if pipeWriter != nil {
			_ = pipeWriter.Close()
		}

		task.AddResult(dto.NewErrorResult(message, requestErrorPayload(errFactory.ByErr("build request", err))))

		return
	}

	applyHeaders(request, payload.Headers)

	if payload.StreamBody {
		// Unknown length → chunked request encoding; the server reads it streamed.
		request.ContentLength = -1
	}

	client := buildClient(
		transportKey{
			connectTimeoutMs:        payload.ConnectTimeoutMs,
			responseHeaderTimeoutMs: payload.ResponseHeaderTimeoutMs,
			verifyTls:               payload.VerifyTls,
			maxIdleConns:            payload.MaxIdleConns,
			maxIdleConnsPerHost:     payload.MaxIdleConnsPerHost,
			idleConnTimeoutMs:       payload.IdleConnTimeoutMs,
			tlsHandshakeTimeoutMs:   payload.TLSHandshakeTimeoutMs,
		},
		payload.FollowRedirects,
		payload.MaxRedirects,
	)

	chunkSize := chunkSizeOrDefault(payload.ChunkSize)

	if payload.StreamBody {
		f.startStreamedRequest(task, ctx, client, request, payload, pipeWriter, chunkSize)

		return
	}

	state := newResponseState(message, client, request, chunkSize, payload.MaxResponseBody)

	result, err := states.Get().Start(ctx, message.TaskKey, state)

	if err != nil {
		state.Close()

		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("start request", err)))

		return
	}

	task.AddResult(result)
}

// applyHeaders copies the PHP-provided headers onto the request. The Host header
// is special in net/http: it must be set on request.Host, not the header map.
func applyHeaders(request *http.Request, headers map[string][]string) {
	for name, values := range headers {
		if strings.EqualFold(name, "Host") {
			if len(values) > 0 {
				request.Host = values[0]
			}

			continue
		}

		for _, value := range values {
			request.Header.Add(name, value)
		}
	}
}

// defaultChunkSize mirrors the HTTP-server transport granularity (64 KiB): the
// inline first-chunk size and the bytes read per streamed body chunk.
const defaultChunkSize = 64 << 10

func chunkSizeOrDefault(chunkSize int) int {
	if chunkSize <= 0 {
		return defaultChunkSize
	}

	return chunkSize
}

func networkErrorPayload(text string) string {
	return networkErrorMarker + ": " + text
}

func requestErrorPayload(text string) string {
	return requestErrorMarker + ": " + text
}
