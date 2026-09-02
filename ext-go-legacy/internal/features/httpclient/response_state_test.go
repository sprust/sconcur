package httpclient_feature

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"sconcur/internal/dto"
	"sconcur/internal/features/httpclient/payloads"

	"github.com/vmihailenco/msgpack/v5"
)

// newTestState builds a responseState targeting url with the given chunk size and
// response-body limit, using the shared client builder.
func newTestState(t *testing.T, url string, chunkSize int, maxResponseBody int64) *responseState {
	t.Helper()

	request, err := http.NewRequest(http.MethodGet, url, nil)

	if err != nil {
		t.Fatalf("build request: %v", err)
	}

	client := buildClient(transportKey{verifyTls: true}, true, 10)

	return newResponseState(&dto.Message{}, client, request, chunkSize, maxResponseBody)
}

// TestResponseStateFirstResultCarriesMetadata checks the first Next returns the
// status, headers and the inline body for a response that fits in one chunk.
func TestResponseStateFirstResultCarriesMetadata(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		writer.Header().Set("X-Test", "yes")
		writer.WriteHeader(http.StatusCreated)
		_, _ = writer.Write([]byte("hello"))
	}))
	defer server.Close()

	state := newTestState(t, server.URL, 1024, 0)
	defer state.Close()

	result := state.Next()

	if result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	if result.HasNext {
		t.Fatal("a 5-byte body should fit in the first chunk (HasNext=false)")
	}

	var meta payloads.ResponseMeta

	if err := msgpack.Unmarshal([]byte(result.Payload), &meta); err != nil {
		t.Fatalf("unmarshal meta: %v", err)
	}

	if meta.Status != http.StatusCreated {
		t.Fatalf("status = %d, want %d", meta.Status, http.StatusCreated)
	}

	if got := meta.Headers["X-Test"]; len(got) != 1 || got[0] != "yes" {
		t.Fatalf("X-Test header = %v, want [yes]", got)
	}

	if meta.Body != "hello" {
		t.Fatalf("body = %q, want %q", meta.Body, "hello")
	}
}

// TestResponseStateStreamsLargeBodyInChunks reassembles a large body from the
// inline first chunk plus the streamed remainder.
func TestResponseStateStreamsLargeBodyInChunks(t *testing.T) {
	data := strings.Repeat("0123456789abcdef", 1000) // 16000 bytes

	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		_, _ = writer.Write([]byte(data))
	}))
	defer server.Close()

	state := newTestState(t, server.URL, 256, 0)
	defer state.Close()

	result := state.Next()

	if result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	if !result.HasNext {
		t.Fatal("a 16000-byte body must stream beyond the first chunk")
	}

	var meta payloads.ResponseMeta

	if err := msgpack.Unmarshal([]byte(result.Payload), &meta); err != nil {
		t.Fatalf("unmarshal meta: %v", err)
	}

	var got strings.Builder

	got.WriteString(meta.Body)

	for range 1000 {
		chunk := state.Next()

		if chunk.IsError {
			t.Fatalf("unexpected error: %s", chunk.Payload)
		}

		got.WriteString(chunk.Payload)

		if !chunk.HasNext {
			break
		}
	}

	if got.String() != data {
		t.Fatalf("reassembled %d bytes, want %d", got.Len(), len(data))
	}
}

// TestResponseStateEnforcesMaxResponseBody checks a body past maxResponseBody ends
// with the stable too-large marker.
func TestResponseStateEnforcesMaxResponseBody(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		_, _ = writer.Write([]byte(strings.Repeat("x", 2000)))
	}))
	defer server.Close()

	state := newTestState(t, server.URL, 256, 500)
	defer state.Close()

	sawTooLarge := false

	for range 1000 {
		result := state.Next()

		if result.IsError {
			if result.Payload != responseBodyTooLargeMessage {
				t.Fatalf("error payload = %q, want %q", result.Payload, responseBodyTooLargeMessage)
			}

			sawTooLarge = true

			break
		}

		if !result.HasNext {
			break
		}
	}

	if !sawTooLarge {
		t.Fatal("expected a too-large error result")
	}
}

// TestResponseStateNetworkErrorIsMarked checks a failed connection surfaces with
// the network-class marker so PHP can raise a PSR-18 NetworkException.
func TestResponseStateNetworkErrorIsMarked(t *testing.T) {
	// Port 1 on loopback refuses the connection.
	state := newTestState(t, "http://127.0.0.1:1", 256, 0)
	defer state.Close()

	result := state.Next()

	if !result.IsError {
		t.Fatal("expected a network error")
	}

	if !strings.HasPrefix(result.Payload, networkErrorMarker+":") {
		t.Fatalf("payload = %q, want a %q-marked error", result.Payload, networkErrorMarker)
	}
}

// TestResponseStateCloseIsIdempotent checks Close after a partial read is safe and
// repeatable.
func TestResponseStateCloseIsIdempotent(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		_, _ = writer.Write([]byte(strings.Repeat("y", 4000)))
	}))
	defer server.Close()

	state := newTestState(t, server.URL, 256, 0)

	if result := state.Next(); result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	state.Close()
	state.Close()
}

// TestDeferredResponseStateCloseWithoutRead checks the deferred (streamed-upload)
// branch of Close: a request whose response is never consumed still forgets its
// pending upload session and unblocks the body pipe, so nothing leaks.
func TestDeferredResponseStateCloseWithoutRead(t *testing.T) {
	pipeReader, pipeWriter := io.Pipe()
	defer func() { _ = pipeReader.Close() }()

	resultReady := make(chan struct{})
	session := &uploadSession{writer: pipeWriter, resultReady: resultReady}
	session.result = &doResult{resp: &http.Response{Body: io.NopCloser(strings.NewReader("x"))}}

	close(resultReady)

	const requestId = "rid-deferred-close"

	pendingUploads.Store(requestId, session)

	state := newDeferredResponseState(&dto.Message{}, session, requestId, 256, 0)

	state.Close()

	if _, ok := pendingUploads.Load(requestId); ok {
		t.Fatal("Close must forget the pending upload session")
	}
}
