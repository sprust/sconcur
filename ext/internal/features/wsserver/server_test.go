package wsserver_feature

import (
	"context"
	"net"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"sconcur/internal/dto"
	"sconcur/internal/features/wsserver/payloads"
	"sconcur/internal/states"
	"sconcur/internal/ws"

	"github.com/coder/websocket"
)

// newTestServer spins up a serverState on a loopback listener and returns it with its
// ws:// URL. The caller drives the stand-in PHP side off state.connections.
func newTestServer(t *testing.T, ctx context.Context, payload payloads.ServePayload) (*serverState, string) {
	t.Helper()

	listener, err := net.Listen("tcp", "127.0.0.1:0")

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	message := &dto.Message{FlowKey: "ws-test-" + listener.Addr().String(), TaskKey: "ws-task"}

	state := newServerState(ctx, message, listener, time.Now(), configFromPayload(payload))

	return state, "ws://" + listener.Addr().String() + "/"
}

// echoConnection stands in for the PHP handler: it reads each inbound message off the
// connection's stream and writes it straight back (preserving text/binary), then
// closes when the input ends.
func echoConnection(event *payloads.ConnectionEvent) {
	inbound := states.Get().GetState(event.ConnectionId + ":in")

	value, ok := pendingConnections.Load(event.ConnectionId)

	if !ok {
		return
	}

	pending := value.(*ws.PendingConnection)

	for {
		result := inbound.Next()

		if !result.HasNext {
			done := make(chan error, 1)
			pending.Commands <- ws.WriteCommand{Kind: ws.OpClose, Done: done}
			<-done

			return
		}

		payload := result.Payload
		messageType := websocket.MessageText

		if payload[0] == ws.MessageTypeBinary {
			messageType = websocket.MessageBinary
		}

		done := make(chan error, 1)
		pending.Commands <- ws.WriteCommand{
			Kind:        ws.OpFrame,
			MessageType: messageType,
			Data:        []byte(payload[1:]),
			Done:        done,
		}

		if err := <-done; err != nil {
			return
		}
	}
}

// serveEcho drains delivered connections and echoes each in its own goroutine.
func serveEcho(ctx context.Context, state *serverState) {
	go func() {
		for {
			select {
			case <-ctx.Done():
				return
			case event := <-state.connections:
				go echoConnection(event)
			}
		}
	}()
}

// TestHandshakeAndEchoText verifies a client can upgrade and round-trip a text message.
func TestHandshakeAndEchoText(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{})
	defer state.Close()

	serveEcho(ctx, state)

	dialCtx, dialCancel := context.WithTimeout(ctx, 2*time.Second)
	defer dialCancel()

	conn, _, err := websocket.Dial(dialCtx, url, nil)

	if err != nil {
		t.Fatalf("dial: %v", err)
	}

	defer func() { _ = conn.CloseNow() }()

	if err := conn.Write(dialCtx, websocket.MessageText, []byte("hello")); err != nil {
		t.Fatalf("write: %v", err)
	}

	messageType, data, err := conn.Read(dialCtx)

	if err != nil {
		t.Fatalf("read: %v", err)
	}

	if messageType != websocket.MessageText {
		t.Fatalf("expected a text echo, got type %v", messageType)
	}

	if string(data) != "hello" {
		t.Fatalf("expected echo \"hello\", got %q", string(data))
	}
}

// TestEchoBinary verifies a binary message round-trips with its type preserved.
func TestEchoBinary(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{})
	defer state.Close()

	serveEcho(ctx, state)

	dialCtx, dialCancel := context.WithTimeout(ctx, 2*time.Second)
	defer dialCancel()

	conn, _, err := websocket.Dial(dialCtx, url, nil)

	if err != nil {
		t.Fatalf("dial: %v", err)
	}

	defer func() { _ = conn.CloseNow() }()

	payload := []byte{0x00, 0x01, 0x02, 0xFF}

	if err := conn.Write(dialCtx, websocket.MessageBinary, payload); err != nil {
		t.Fatalf("write: %v", err)
	}

	messageType, data, err := conn.Read(dialCtx)

	if err != nil {
		t.Fatalf("read: %v", err)
	}

	if messageType != websocket.MessageBinary {
		t.Fatalf("expected a binary echo, got type %v", messageType)
	}

	if string(data) != string(payload) {
		t.Fatalf("binary echo mismatch: got %v want %v", data, payload)
	}
}

// TestNonWebSocketRequestRejected verifies a plain HTTP request is answered 426.
func TestNonWebSocketRequestRejected(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{})
	defer state.Close()

	httpURL := strings.Replace(url, "ws://", "http://", 1)

	response, err := http.Get(httpURL)

	if err != nil {
		t.Fatalf("http get: %v", err)
	}

	defer func() { _ = response.Body.Close() }()

	if response.StatusCode != http.StatusUpgradeRequired {
		t.Fatalf("expected 426 Upgrade Required for a non-WS request, got %d", response.StatusCode)
	}
}

// TestUpgradeOnConfiguredPathOnly verifies a path restriction: an upgrade to the
// configured path succeeds, while another path is answered 404 (the dial fails).
func TestUpgradeOnConfiguredPathOnly(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{Path: "/chat"})
	defer state.Close()

	serveEcho(ctx, state)

	dialCtx, dialCancel := context.WithTimeout(ctx, 2*time.Second)
	defer dialCancel()

	// Wrong path → 404, dial fails.
	if conn, _, err := websocket.Dial(dialCtx, url, nil); err == nil {
		_ = conn.CloseNow()

		t.Fatal("expected the dial to the wrong path to fail (404)")
	}

	// Configured path → upgrade succeeds.
	conn, _, err := websocket.Dial(dialCtx, url+"chat", nil)

	if err != nil {
		t.Fatalf("dial to the configured path: %v", err)
	}

	_ = conn.CloseNow()
}

// TestOriginCheckRejectsForeignOrigin verifies that with allowedOrigins set, a request
// carrying a foreign Origin is rejected (403, dial fails), while a request whose Origin
// matches an allowed pattern is upgraded.
func TestOriginCheckRejectsForeignOrigin(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{AllowedOrigins: []string{"good.example"}})
	defer state.Close()

	serveEcho(ctx, state)

	dialCtx, dialCancel := context.WithTimeout(ctx, 2*time.Second)
	defer dialCancel()

	foreign := &websocket.DialOptions{HTTPHeader: http.Header{"Origin": []string{"http://evil.example"}}}

	if conn, _, err := websocket.Dial(dialCtx, url, foreign); err == nil {
		_ = conn.CloseNow()

		t.Fatal("expected a foreign Origin to be rejected (403)")
	}

	allowed := &websocket.DialOptions{HTTPHeader: http.Header{"Origin": []string{"http://good.example"}}}

	conn, _, err := websocket.Dial(dialCtx, url, allowed)

	if err != nil {
		t.Fatalf("dial with an allowed Origin: %v", err)
	}

	_ = conn.CloseNow()
}

// TestIdleTimeoutEndsConnection verifies the idle read timeout ends an idle connection.
func TestIdleTimeoutEndsConnection(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{IdleTimeoutMs: 100, PingIntervalMs: 0})
	defer state.Close()

	serveEcho(ctx, state)

	dialCtx, dialCancel := context.WithTimeout(ctx, 2*time.Second)
	defer dialCancel()

	conn, _, err := websocket.Dial(dialCtx, url, nil)

	if err != nil {
		t.Fatalf("dial: %v", err)
	}

	defer func() { _ = conn.CloseNow() }()

	// Send nothing: the server's idle timeout must end the connection, observed as a
	// read error on the client.
	_, _, err = conn.Read(dialCtx)

	if err == nil {
		t.Fatal("expected the idle timeout to end the connection")
	}
}

// TestMaxConcurrency verifies the in-flight limiter never lets more than the cap of
// connections be handled at once, while still reaching the cap in parallel.
func TestMaxConcurrency(t *testing.T) {
	const limit = 2
	const clients = 8

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{MaxConcurrency: limit})
	defer state.Close()

	var inFlight, maxSeen int32

	go func() {
		for {
			select {
			case <-ctx.Done():
				return
			case event := <-state.connections:
				go func(event *payloads.ConnectionEvent) {
					current := atomic.AddInt32(&inFlight, 1)

					for {
						peak := atomic.LoadInt32(&maxSeen)

						if current <= peak || atomic.CompareAndSwapInt32(&maxSeen, peak, current) {
							break
						}
					}

					time.Sleep(80 * time.Millisecond)

					atomic.AddInt32(&inFlight, -1)

					if value, ok := pendingConnections.Load(event.ConnectionId); ok {
						done := make(chan error, 1)
						value.(*ws.PendingConnection).Commands <- ws.WriteCommand{Kind: ws.OpClose, Done: done}
						<-done
					}
				}(event)
			}
		}
	}()

	var waitGroup sync.WaitGroup

	for range clients {
		waitGroup.Add(1)

		go func() {
			defer waitGroup.Done()

			dialCtx, dialCancel := context.WithTimeout(ctx, 3*time.Second)
			defer dialCancel()

			conn, _, err := websocket.Dial(dialCtx, url, nil)

			if err != nil {
				return
			}

			// Block until the server closes the connection.
			_, _, _ = conn.Read(dialCtx)

			_ = conn.CloseNow()
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

// TestOversizeMessageClosesConnection verifies a message past maxMessageBytes ends the
// connection with 1009 (message too big).
func TestOversizeMessageClosesConnection(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{MaxMessageBytes: 16})
	defer state.Close()

	serveEcho(ctx, state)

	dialCtx, dialCancel := context.WithTimeout(ctx, 2*time.Second)
	defer dialCancel()

	conn, _, err := websocket.Dial(dialCtx, url, nil)

	if err != nil {
		t.Fatalf("dial: %v", err)
	}

	defer func() { _ = conn.CloseNow() }()

	// Disable the client's own read limit so it observes the server's close rather than
	// tripping its own.
	conn.SetReadLimit(-1)

	if err := conn.Write(dialCtx, websocket.MessageText, []byte(strings.Repeat("x", 1024))); err != nil {
		t.Fatalf("write: %v", err)
	}

	_, _, err = conn.Read(dialCtx)

	if err == nil {
		t.Fatal("expected the oversize message to close the connection")
	}

	if websocket.CloseStatus(err) != websocket.StatusMessageTooBig {
		t.Fatalf("expected close status 1009 (message too big), got %v (err: %v)", websocket.CloseStatus(err), err)
	}
}

// TestStopAcceptingClosesListener verifies stopAccepting closes the listener (so a
// SO_REUSEPORT sibling takes over) without needing the flow to be cancelled.
func TestStopAcceptingClosesListener(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{})
	defer state.Close()

	address := strings.TrimSuffix(strings.TrimPrefix(url, "ws://"), "/")

	connection, err := net.DialTimeout("tcp", address, time.Second)

	if err != nil {
		t.Fatalf("expected to connect before stopAccepting: %v", err)
	}

	_ = connection.Close()

	state.stopAccepting()

	refused := false

	for range 50 {
		next, err := net.DialTimeout("tcp", address, 200*time.Millisecond)

		if err != nil {
			refused = true

			break
		}

		_ = next.Close()
		time.Sleep(20 * time.Millisecond)
	}

	if !refused {
		t.Fatal("listener should be closed (connections refused) after stopAccepting")
	}
}

// TestServerPingKeepsConnectionAlive verifies the server keepalive ping keeps an idle
// connection usable past several ping intervals.
func TestServerPingKeepsConnectionAlive(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	state, url := newTestServer(t, ctx, payloads.ServePayload{PingIntervalMs: 50})
	defer state.Close()

	serveEcho(ctx, state)

	dialCtx, dialCancel := context.WithTimeout(ctx, 3*time.Second)
	defer dialCancel()

	conn, _, err := websocket.Dial(dialCtx, url, nil)

	if err != nil {
		t.Fatalf("dial: %v", err)
	}

	defer func() { _ = conn.CloseNow() }()

	// Stay idle across several ping intervals; the client library answers pings
	// automatically, so the connection must still be alive afterwards.
	time.Sleep(300 * time.Millisecond)

	if err := conn.Write(dialCtx, websocket.MessageText, []byte("still here")); err != nil {
		t.Fatalf("write after idle: %v", err)
	}

	_, data, err := conn.Read(dialCtx)

	if err != nil {
		t.Fatalf("read after idle: %v", err)
	}

	if string(data) != "still here" {
		t.Fatalf("expected echo after idle, got %q", string(data))
	}
}
