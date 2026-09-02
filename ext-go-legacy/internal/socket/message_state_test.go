package socket

import (
	"bufio"
	"net"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"testing"
	"time"
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

func newState(conn net.Conn, readTimeout time.Duration, maxMessageBytes int) *MessageState {
	factory := errs.NewErrorsFactory("test")

	return NewMessageState(&dto.Message{}, conn, bufio.NewReader(conn), readTimeout, maxMessageBytes, factory)
}

func TestMessageStateReadsInboundFrames(t *testing.T) {
	client, server := dialPair(t)

	state := newState(client, 0, 0)

	if err := WriteFrame(server, []byte("hello")); err != nil {
		t.Fatalf("WriteFrame: %v", err)
	}

	result := state.Next()

	if result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	if !result.HasNext {
		t.Fatal("expected HasNext while the connection is open")
	}

	if result.Payload != "hello" {
		t.Fatalf("payload: got %q, want %q", result.Payload, "hello")
	}
}

func TestMessageStateEndsCleanlyOnPeerClose(t *testing.T) {
	client, server := dialPair(t)

	state := newState(client, 0, 0)

	_ = server.Close()

	result := state.Next()

	if result.IsError {
		t.Fatalf("a clean peer close must not be an error, got: %s", result.Payload)
	}

	if result.HasNext {
		t.Fatal("expected the stream to end (HasNext=false) on peer close")
	}
}

func TestMessageStateEndsCleanlyOnReadTimeout(t *testing.T) {
	client, _ := dialPair(t)

	state := newState(client, 50*time.Millisecond, 0)

	// The peer sends nothing: the idle read deadline elapses and ends the stream
	// cleanly rather than surfacing an error.
	result := state.Next()

	if result.IsError {
		t.Fatalf("a read timeout must end the stream cleanly, got: %s", result.Payload)
	}

	if result.HasNext {
		t.Fatal("expected the stream to end (HasNext=false) on idle timeout")
	}
}

func TestMessageStateErrorsOnOversizeFrame(t *testing.T) {
	client, server := dialPair(t)

	state := newState(client, 0, 4)

	if err := WriteFrame(server, []byte("way too long")); err != nil {
		t.Fatalf("WriteFrame: %v", err)
	}

	result := state.Next()

	if !result.IsError {
		t.Fatal("an inbound frame larger than maxMessageBytes must be an error result")
	}
}
