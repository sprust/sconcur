package socketclient_feature

import (
	"bufio"
	"net"
	"sconcur/internal/dto"
	"sconcur/internal/features/socketclient/payloads"
	"sconcur/internal/socket"
	"testing"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// dialPair returns a connected client/server TCP pair on loopback, both closed by the
// test cleanup.
func dialPair(t *testing.T) (net.Conn, net.Conn) {
	t.Helper()

	listener, err := net.Listen("tcp", "127.0.0.1:0")

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	defer listener.Close()

	type accepted struct {
		conn net.Conn
		err  error
	}

	accept := make(chan accepted, 1)

	go func() {
		conn, err := listener.Accept()

		accept <- accepted{conn: conn, err: err}
	}()

	client, err := net.Dial("tcp", listener.Addr().String())

	if err != nil {
		t.Fatalf("dial: %v", err)
	}

	result := <-accept

	if result.err != nil {
		t.Fatalf("accept: %v", result.err)
	}

	t.Cleanup(func() {
		_ = client.Close()
		_ = result.conn.Close()
	})

	return client, result.conn
}

func newConnectionState(conn net.Conn, connectionId string) *connectionState {
	return &connectionState{
		message:      &dto.Message{},
		connectionId: connectionId,
		remoteAddr:   conn.RemoteAddr().String(),
		localAddr:    conn.LocalAddr().String(),
		startTime:    time.Now(),
		inbound:      socket.NewMessageState(&dto.Message{}, conn, bufio.NewReader(conn), 0, 0, errFactory),
		cleanup:      func() {},
	}
}

// TestConnectionStateFirstResultCarriesMetadata checks the first Next returns the
// connection metadata (id + addresses) with HasNext set, keeping the stream open.
func TestConnectionStateFirstResultCarriesMetadata(t *testing.T) {
	client, _ := dialPair(t)

	state := newConnectionState(client, "flow:c:1")

	result := state.Next()

	if result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	if !result.HasNext {
		t.Fatal("the metadata result must keep the stream open (HasNext=true)")
	}

	var meta payloads.ConnectionMeta

	if err := msgpack.Unmarshal([]byte(result.Payload), &meta); err != nil {
		t.Fatalf("unmarshal meta: %v", err)
	}

	if meta.ConnectionId != "flow:c:1" {
		t.Fatalf("connectionId: got %q, want %q", meta.ConnectionId, "flow:c:1")
	}

	if meta.RemoteAddr == "" || meta.LocalAddr == "" {
		t.Fatalf("addresses must be populated, got remote=%q local=%q", meta.RemoteAddr, meta.LocalAddr)
	}
}

// TestConnectionStateStreamsInboundAfterMetadata checks subsequent Next calls return
// inbound frames, then end cleanly when the peer closes.
func TestConnectionStateStreamsInboundAfterMetadata(t *testing.T) {
	client, server := dialPair(t)

	state := newConnectionState(client, "flow:c:2")

	// Drain the metadata result first.
	if meta := state.Next(); meta.IsError || !meta.HasNext {
		t.Fatalf("metadata result: error=%v hasNext=%v", meta.IsError, meta.HasNext)
	}

	if err := socket.WriteFrame(server, []byte("frame-1")); err != nil {
		t.Fatalf("WriteFrame: %v", err)
	}

	frame := state.Next()

	if frame.IsError || !frame.HasNext {
		t.Fatalf("inbound frame: error=%v hasNext=%v payload=%q", frame.IsError, frame.HasNext, frame.Payload)
	}

	if frame.Payload != "frame-1" {
		t.Fatalf("payload: got %q, want %q", frame.Payload, "frame-1")
	}

	_ = server.Close()

	end := state.Next()

	if end.IsError {
		t.Fatalf("a peer close must end the stream cleanly, got: %s", end.Payload)
	}

	if end.HasNext {
		t.Fatal("expected the inbound stream to end (HasNext=false) on peer close")
	}
}

// TestConnectionStateCloseRunsCleanup checks Close invokes the cleanup hook (which the
// feature uses to drop the connection from the registry and close the socket).
func TestConnectionStateCloseRunsCleanup(t *testing.T) {
	client, _ := dialPair(t)

	cleaned := false

	state := newConnectionState(client, "flow:c:3")
	state.cleanup = func() {
		cleaned = true
	}

	state.Close()

	if !cleaned {
		t.Fatal("Close must run the cleanup hook")
	}
}
