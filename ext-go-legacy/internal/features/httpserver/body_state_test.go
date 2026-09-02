package httpserver_feature

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"sconcur/internal/dto"
)

// TestBodyStateStreamsChunksThenEOF reassembles a body from the chunks bodyState
// yields and checks it ends cleanly.
func TestBodyStateStreamsChunksThenEOF(t *testing.T) {
	data := strings.Repeat("abcd", 1000) // 4000 bytes

	state := newBodyState(&dto.Message{}, strings.NewReader(data), 256)

	var got strings.Builder

	for range 100 {
		result := state.Next()

		if result.IsError {
			t.Fatalf("unexpected error: %s", result.Payload)
		}

		got.WriteString(result.Payload)

		if !result.HasNext {
			break
		}
	}

	if got.String() != data {
		t.Fatalf("reassembled %d bytes, want %d", got.Len(), len(data))
	}
}

// TestBodyStateReportsTooLarge verifies a body past the MaxBytesReader limit ends
// with the stable too-large marker.
func TestBodyStateReportsTooLarge(t *testing.T) {
	body := io.NopCloser(strings.NewReader(strings.Repeat("x", 2000)))
	reader := http.MaxBytesReader(httptest.NewRecorder(), body, 500)

	state := newBodyState(&dto.Message{}, reader, 256)

	sawError := false

	for range 100 {
		result := state.Next()

		if result.IsError {
			sawError = true

			if result.Payload != bodyTooLargeMessage {
				t.Fatalf("want %q, got %q", bodyTooLargeMessage, result.Payload)
			}

			break
		}

		if !result.HasNext {
			break
		}
	}

	if !sawError {
		t.Fatal("expected a too-large error result")
	}
}
