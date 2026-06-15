package httpclient_feature

import (
	"errors"
	"io"
	"net/http"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/features/httpclient/payloads"
	"sconcur/internal/helpers"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.StateContract = (*responseState)(nil)

// responseBodyTooLargeMessage is the error payload returned when the response body
// exceeds maxResponseBody mid-stream. A plain client error (no net/req marker).
const responseBodyTooLargeMessage = "response body too large"

// errResponseBodyTooLarge is returned by the limiting reader once the response
// body grows past maxResponseBody.
var errResponseBodyTooLarge = errors.New(responseBodyTooLargeMessage)

// responseState streams one HTTP response to PHP, mirroring the Mongo cursor /
// request-body states. The first Next performs the request and returns the
// response metadata (status, headers, inline first chunk); each subsequent Next
// returns a raw body chunk. Implements contracts.StateContract.
type responseState struct {
	// mutex serializes Next against Close: Close may fire from the task context
	// cancellation while a Next call is still using the body.
	mutex           sync.Mutex
	message         *dto.Message
	client          *http.Client
	request         *http.Request
	chunkSize       int
	maxResponseBody int64
	startTime       time.Time
	resp            *http.Response
	bodyReader      io.Reader
	requested       bool
}

func newResponseState(
	message *dto.Message,
	client *http.Client,
	request *http.Request,
	chunkSize int,
	maxResponseBody int64,
) *responseState {
	return &responseState{
		message:         message,
		client:          client,
		request:         request,
		chunkSize:       chunkSize,
		maxResponseBody: maxResponseBody,
		startTime:       time.Now(),
	}
}

func (s *responseState) Next() *dto.Result {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	if !s.requested {
		return s.sendAndReadMeta()
	}

	return s.readBodyChunk()
}

// sendAndReadMeta performs the request, then emits the response metadata plus the
// inline first chunk of the body. HasNext is set when the body is not yet
// exhausted, so PHP keeps pulling chunks via next().
func (s *responseState) sendAndReadMeta() *dto.Result {
	s.requested = true

	resp, err := s.client.Do(s.request)

	if err != nil {
		// Connection/DNS/timeout/redirect failures are network-class (PSR-18).
		return dto.NewErrorResult(s.message, networkErrorPayload(err.Error()))
	}

	s.resp = resp
	s.bodyReader = resp.Body

	if s.maxResponseBody > 0 {
		s.bodyReader = &maxBytesReader{reader: resp.Body, remaining: s.maxResponseBody}
	}

	chunk, eof, err := helpers.ReadChunk(s.bodyReader, s.chunkSize)

	if err != nil {
		return dto.NewErrorResult(s.message, readErrorMessage(err))
	}

	meta := payloads.ResponseMeta{
		Status:        resp.StatusCode,
		Headers:       resp.Header,
		Body:          string(chunk),
		ContentLength: resp.ContentLength,
	}

	serialized, err := msgpack.Marshal(meta)

	if err != nil {
		return dto.NewErrorResult(s.message, errFactory.ByErr("marshal response meta", err))
	}

	if eof {
		return dto.NewSuccessResult(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
	}

	return dto.NewSuccessResultWithNext(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
}

// readBodyChunk returns the next raw chunk of the response body. The last chunk
// carries HasNext=false, ending the stream (and deleting the state → Close).
func (s *responseState) readBodyChunk() *dto.Result {
	if s.bodyReader == nil {
		return dto.NewErrorResult(s.message, errFactory.ByText("response body not started"))
	}

	chunk, eof, err := helpers.ReadChunk(s.bodyReader, s.chunkSize)

	if err != nil {
		return dto.NewErrorResult(s.message, readErrorMessage(err))
	}

	if eof {
		return dto.NewSuccessResult(s.message, string(chunk), helpers.CalcExecutionMs(s.startTime))
	}

	return dto.NewSuccessResultWithNext(s.message, string(chunk), helpers.CalcExecutionMs(s.startTime))
}

// Close releases the response body. Called once when the stream is exhausted, the
// task context is cancelled (early abandon) or the flow is stopped. Body.Close
// needs no context, so a cancelled task context does not block cleanup.
func (s *responseState) Close() {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	if s.resp != nil {
		_ = s.resp.Body.Close()
		s.resp = nil
		s.bodyReader = nil
	}
}

// maxBytesReader caps how many bytes may be read from the response body. Past the
// limit it returns errResponseBodyTooLarge instead of more data. Modeled on
// net/http.MaxBytesReader, which guards server request bodies the same way.
type maxBytesReader struct {
	reader    io.Reader
	remaining int64
	err       error
}

func (r *maxBytesReader) Read(buffer []byte) (int, error) {
	if r.err != nil {
		return 0, r.err
	}

	if len(buffer) == 0 {
		return 0, nil
	}

	// Read at most one byte past the remaining budget so an exact-fit body is not
	// flagged, but a larger one is.
	if int64(len(buffer)) > r.remaining+1 {
		buffer = buffer[:r.remaining+1]
	}

	read, err := r.reader.Read(buffer)

	if int64(read) <= r.remaining {
		r.remaining -= int64(read)

		return read, err
	}

	read = int(r.remaining)
	r.remaining = 0
	r.err = errResponseBodyTooLarge

	return read, r.err
}

// readErrorMessage maps a body read error to the payload PHP receives: a stable
// marker for the over-limit case, the raw error otherwise.
func readErrorMessage(err error) string {
	if errors.Is(err, errResponseBodyTooLarge) {
		return responseBodyTooLargeMessage
	}

	// A failure reading the body after the connection succeeded is network-class.
	return networkErrorPayload(err.Error())
}
