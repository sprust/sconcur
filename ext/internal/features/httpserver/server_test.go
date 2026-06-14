package httpserver_feature

import (
	"context"
	"net"
	"net/http"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"sconcur/internal/dto"
	"sconcur/internal/features/httpserver/payloads"
)

// TestServeHTTPRespectsMaxConcurrency verifies the in-flight limiter: with a cap
// of 2, no more than 2 requests are ever handled at once even when many arrive
// together, while still allowing the full 2 to run in parallel.
func TestServeHTTPRespectsMaxConcurrency(t *testing.T) {
	const limit = 2
	const requests = 8

	listener, err := net.Listen("tcp", "127.0.0.1:0")

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	message := &dto.Message{FlowKey: "test-flow", TaskKey: "test-task"}

	config := configFromPayload(payloads.ServePayload{MaxConcurrency: limit})

	state := newServerState(ctx, message, listener, time.Now(), config)
	defer state.Close()

	var inFlight, maxSeen int32

	// Consumer: stands in for the PHP side — pulls each delivered request, holds
	// it briefly (so concurrency is observable), then answers it.
	go func() {
		for {
			select {
			case <-ctx.Done():
				return
			case event := <-state.requests:
				go answer(event, &inFlight, &maxSeen)
			}
		}
	}()

	var waitGroup sync.WaitGroup

	for range requests {
		waitGroup.Add(1)

		go func() {
			defer waitGroup.Done()

			response, err := http.Get("http://" + listener.Addr().String() + "/")

			if err == nil {
				_ = response.Body.Close()
			}
		}()
	}

	waitGroup.Wait()

	seen := atomic.LoadInt32(&maxSeen)

	if seen > limit {
		t.Fatalf("max in-flight %d exceeded the limit %d", seen, limit)
	}

	if seen != limit {
		t.Fatalf("expected the limiter to reach %d in parallel, peaked at %d", limit, seen)
	}
}

// TestServeHTTPAnswers503OnShutdown verifies that a request accepted but not yet
// answered when the server stops gets a 503, not a dropped connection.
func TestServeHTTPAnswers503OnShutdown(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	message := &dto.Message{FlowKey: "drain-flow", TaskKey: "drain-task"}

	state := newServerState(ctx, message, listener, time.Now(), configFromPayload(payloads.ServePayload{}))
	defer state.Close()

	// Stand-in PHP side: accept the request but never answer it.
	delivered := make(chan struct{})

	go func() {
		select {
		case <-ctx.Done():
		case <-state.requests:
			close(delivered)
		}
	}()

	status := make(chan int, 1)

	go func() {
		response, err := http.Get("http://" + listener.Addr().String() + "/")

		if err != nil {
			status <- -1

			return
		}

		defer func() { _ = response.Body.Close() }()

		status <- response.StatusCode
	}()

	select {
	case <-delivered:
	case <-time.After(2 * time.Second):
		t.Fatal("request was not delivered")
	}

	// Simulate a graceful stop (what stopFlow does): cancel the server context.
	cancel()

	select {
	case got := <-status:
		if got != http.StatusServiceUnavailable {
			t.Fatalf("expected 503 on shutdown, got %d", got)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("client never got a response")
	}
}

func answer(event *payloads.RequestEvent, inFlight, maxSeen *int32) {
	current := atomic.AddInt32(inFlight, 1)

	for {
		peak := atomic.LoadInt32(maxSeen)

		if current <= peak || atomic.CompareAndSwapInt32(maxSeen, peak, current) {
			break
		}
	}

	time.Sleep(50 * time.Millisecond)

	// Leave the measured window before responding: the response releases the
	// server's slot, and a freed slot lets the next request in — counting it only
	// after this decrement keeps the gauge aligned with real slot occupancy.
	atomic.AddInt32(inFlight, -1)

	value, ok := pendingRequests.Load(event.RequestId)

	if ok {
		done := make(chan error, 1)

		value.(chan writeCommand) <- writeCommand{kind: writeFull, status: 200, body: "ok", done: done}

		<-done
	}
}
