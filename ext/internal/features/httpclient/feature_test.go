package httpclient_feature

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"sconcur/internal/dto"
	"sconcur/internal/features/httpclient/payloads"
	"sconcur/internal/tasks"
	"sconcur/internal/types"

	"github.com/vmihailenco/msgpack/v5"
)

// TestHandleRejectsInvalidRequestWithReqMarker checks a request that cannot even
// be built (invalid HTTP method) surfaces as a request-class error, so PHP raises
// a PSR-18 RequestException.
func TestHandleRejectsInvalidRequestWithReqMarker(t *testing.T) {
	payload := payloads.RequestPayload{Method: "BAD METHOD", Url: "http://127.0.0.1"}

	data, err := msgpack.Marshal(payload)

	if err != nil {
		t.Fatalf("marshal payload: %v", err)
	}

	message := &dto.Message{Method: types.MethodHttpClient, FlowKey: "f", TaskKey: "t", Payload: data}
	results := make(chan *dto.Result, 1)
	task := tasks.NewTask(context.Background(), results, message)

	Get().Handle(task)

	result := <-results

	if !result.IsError {
		t.Fatal("expected a request error")
	}

	if !strings.HasPrefix(result.Payload, requestErrorMarker+":") {
		t.Fatalf("payload = %q, want a %q-marked error", result.Payload, requestErrorMarker)
	}
}

// TestRedirectPolicy covers the three redirect modes: disabled, within the limit,
// and over the limit.
func TestRedirectPolicy(t *testing.T) {
	if err := redirectPolicy(false, 10)(nil, nil); err != http.ErrUseLastResponse {
		t.Fatalf("disabled: err = %v, want ErrUseLastResponse", err)
	}

	if err := redirectPolicy(true, 3)(nil, []*http.Request{{}}); err != nil {
		t.Fatalf("within limit: err = %v, want nil", err)
	}

	if err := redirectPolicy(true, 3)(nil, []*http.Request{{}, {}, {}}); err != errTooManyRedirects {
		t.Fatalf("over limit: err = %v, want errTooManyRedirects", err)
	}
}

// TestApplyHeadersIncludingHost checks request headers reach the server and the
// Host header is applied to request.Host (net/http does not carry it in the map).
func TestApplyHeadersIncludingHost(t *testing.T) {
	var gotHost, gotHeader string

	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		gotHost = request.Host
		gotHeader = request.Header.Get("X-Echo")
	}))
	defer server.Close()

	request, err := http.NewRequest(http.MethodGet, server.URL, nil)

	if err != nil {
		t.Fatalf("build request: %v", err)
	}

	applyHeaders(request, map[string][]string{"X-Echo": {"hi"}, "Host": {"example.test"}})

	state := newResponseState(&dto.Message{}, buildClient(transportKey{verifyTls: true}, true, 10), request, 1024, 0)
	defer state.Close()

	if result := state.Next(); result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	if gotHeader != "hi" {
		t.Fatalf("X-Echo = %q, want %q", gotHeader, "hi")
	}

	if gotHost != "example.test" {
		t.Fatalf("Host = %q, want %q", gotHost, "example.test")
	}
}

// TestResponseStateFollowsRedirects checks a 302 is followed to the final 200.
func TestResponseStateFollowsRedirects(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/final", func(writer http.ResponseWriter, _ *http.Request) {
		_, _ = writer.Write([]byte("arrived"))
	})
	mux.HandleFunc("/start", func(writer http.ResponseWriter, request *http.Request) {
		http.Redirect(writer, request, "/final", http.StatusFound)
	})

	server := httptest.NewServer(mux)
	defer server.Close()

	request, err := http.NewRequest(http.MethodGet, server.URL+"/start", nil)

	if err != nil {
		t.Fatalf("build request: %v", err)
	}

	state := newResponseState(&dto.Message{}, buildClient(transportKey{verifyTls: true}, true, 10), request, 1024, 0)
	defer state.Close()

	result := state.Next()

	if result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	var meta payloads.ResponseMeta

	if err := msgpack.Unmarshal([]byte(result.Payload), &meta); err != nil {
		t.Fatalf("unmarshal meta: %v", err)
	}

	if meta.Status != http.StatusOK {
		t.Fatalf("status = %d, want 200", meta.Status)
	}

	if meta.Body != "arrived" {
		t.Fatalf("body = %q, want %q", meta.Body, "arrived")
	}
}
