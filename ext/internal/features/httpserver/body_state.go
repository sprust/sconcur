package httpserver_feature

import (
	"errors"
	"io"
	"net/http"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/helpers"
	"sync"
	"time"
)

var _ contracts.StateContract = (*bodyState)(nil)

// bodyTooLargeMessage is the exact error payload returned when the request body
// exceeds maxRequestBody mid-stream. The PHP side matches on it to surface a 413
// rather than a generic 500.
const bodyTooLargeMessage = "request body too large"

// bodyState streams the remainder of a request body to PHP, one chunk per Next
// (modeled on the Mongo cursor states). The inline first chunk is read by
// ServeHTTP; this state continues from the same reader for the rest.
type bodyState struct {
	mutex     sync.Mutex
	message   *dto.Message
	reader    io.Reader
	chunkSize int
	startTime time.Time
}

func newBodyState(message *dto.Message, reader io.Reader, chunkSize int) *bodyState {
	return &bodyState{
		message:   message,
		reader:    reader,
		chunkSize: chunkSize,
		startTime: time.Now(),
	}
}

func (s *bodyState) Next() *dto.Result {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	chunk, eof, err := helpers.ReadChunk(s.reader, s.chunkSize)

	if err != nil {
		return dto.NewErrorResult(s.message, readErrorMessage(err))
	}

	if eof {
		return dto.NewSuccessResult(s.message, string(chunk), helpers.CalcExecutionMs(s.startTime))
	}

	return dto.NewSuccessResultWithNext(s.message, string(chunk), helpers.CalcExecutionMs(s.startTime))
}

func (s *bodyState) Close() {
	// The body itself is closed by net/http when ServeHTTP returns; nothing to do.
}

// readErrorMessage maps a body read error to the payload PHP receives: a stable
// marker for the over-limit case (→ 413), the raw error otherwise.
func readErrorMessage(err error) string {
	var maxBytesError *http.MaxBytesError

	if errors.As(err, &maxBytesError) {
		return bodyTooLargeMessage
	}

	return errFactory.ByErr("read request body", err)
}
