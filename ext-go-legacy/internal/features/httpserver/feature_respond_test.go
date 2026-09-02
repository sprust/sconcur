package httpserver_feature

import (
	"context"
	"io"
	"net"
	"net/http"
	"testing"
	"time"

	"sconcur/internal/dto"
	"sconcur/internal/features/httpserver/payloads"
	"sconcur/internal/tasks"

	"github.com/vmihailenco/msgpack/v5"
)

// respondTask builds the detached task a fire-and-forget respond arrives as: no
// flow key, so nothing on the PHP side ever claims its result.
func respondTask(t *testing.T, payload payloads.RespondPayload) (*tasks.Task, chan *dto.Result) {
	t.Helper()

	encoded, err := msgpack.Marshal(payload)

	if err != nil {
		t.Fatalf("marshal respond payload: %v", err)
	}

	results := make(chan *dto.Result, 4)

	message := &dto.Message{
		Method:  "hr",
		TaskKey: ":1",
		Payload: encoded,
	}

	return tasks.NewTask(context.Background(), results, message), results
}

// The fire-and-forget write carries no done channel — nothing awaits it, so none
// is allocated. The connection side must still apply the write and skip the
// report: a send on a nil channel would block the connection goroutine forever,
// leaving the client hanging until a timeout.
func TestFireAndForgetRespondWritesTheResponse(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	message := &dto.Message{FlowKey: "ff-flow", TaskKey: "ff-task"}

	state := newServerState(ctx, message, listener, time.Now(), configFromPayload(payloads.ServePayload{}))
	defer state.Close()

	feature := Get()

	// Stands in for the PHP side: pull the request event and answer it exactly
	// the way HttpServer::handle does for a known-size body — execNoResult.
	go func() {
		for {
			select {
			case <-ctx.Done():
				return
			case event := <-state.requests:
				task, _ := respondTask(t, payloads.RespondPayload{
					RequestId: event.RequestId,
					Op:        int(writeFull),
					Status:    201,
					Headers:   map[string][]string{"X-Answered": {"detached"}},
					Body:      "ok",
					NoResult:  true,
				})

				feature.Handle(task)
			}
		}
	}()

	client := &http.Client{Timeout: 5 * time.Second}

	response, err := client.Get("http://" + listener.Addr().String() + "/")

	if err != nil {
		t.Fatalf("request: %v", err)
	}

	defer func() { _ = response.Body.Close() }()

	body, err := io.ReadAll(response.Body)

	if err != nil {
		t.Fatalf("read body: %v", err)
	}

	if response.StatusCode != 201 {
		t.Fatalf("expected 201, got %d", response.StatusCode)
	}

	if string(body) != "ok" {
		t.Fatalf("expected body %q, got %q", "ok", string(body))
	}

	if got := response.Header.Get("X-Answered"); got != "detached" {
		t.Fatalf("expected the headers to survive the detached write, got %q", got)
	}
}

// A fire-and-forget respond publishes nothing at all — not even on success. The
// PHP coroutine is already gone by then, so a result would only be dropped.
func TestFireAndForgetRespondPublishesNoResult(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	message := &dto.Message{FlowKey: "ff-none-flow", TaskKey: "ff-none-task"}

	state := newServerState(ctx, message, listener, time.Now(), configFromPayload(payloads.ServePayload{}))
	defer state.Close()

	feature := Get()

	published := make(chan *dto.Result, 4)

	go func() {
		select {
		case <-ctx.Done():
		case event := <-state.requests:
			task, results := respondTask(t, payloads.RespondPayload{
				RequestId: event.RequestId,
				Op:        int(writeFull),
				Status:    200,
				Body:      "ok",
				NoResult:  true,
			})

			feature.Handle(task)

			select {
			case result := <-results:
				published <- result
			default:
				close(published)
			}
		}
	}()

	client := &http.Client{Timeout: 5 * time.Second}

	response, err := client.Get("http://" + listener.Addr().String() + "/")

	if err != nil {
		t.Fatalf("request: %v", err)
	}

	_ = response.Body.Close()

	select {
	case result, ok := <-published:
		if ok {
			t.Fatalf("a fire-and-forget respond must publish nothing, got %+v", result)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("the responder never finished")
	}
}

// An unroutable detached respond (the connection is already gone) must fail
// without touching the connection side and without hanging: the error result is
// published for symmetry — the Go handler drops it, having logged it first.
func TestDetachedRespondWithUnknownRequestIdDoesNotHang(t *testing.T) {
	feature := Get()

	task, results := respondTask(t, payloads.RespondPayload{
		RequestId: "no-such-request",
		Op:        int(writeFull),
		Status:    200,
		Body:      "ok",
		NoResult:  true,
	})

	done := make(chan struct{})

	go func() {
		feature.Handle(task)

		close(done)
	}()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("an unroutable detached respond hung")
	}

	select {
	case result := <-results:
		if !result.IsError {
			t.Fatalf("expected an error result, got %+v", result)
		}
	default:
		t.Fatal("expected the failure to be reported as an error result")
	}
}
