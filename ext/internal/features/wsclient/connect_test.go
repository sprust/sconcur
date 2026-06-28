package wsclient_feature

import (
	"context"
	"sconcur/internal/dto"
	"sconcur/internal/features/wsclient/payloads"
	"sconcur/internal/ws"
	"testing"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

func newTestConnectionState(connectionId string, messages chan ws.InboundMessage) *connectionState {
	return &connectionState{
		ctx:          context.Background(),
		message:      &dto.Message{},
		connectionId: connectionId,
		remoteAddr:   "127.0.0.1:9200",
		localAddr:    "",
		subprotocol:  "chat",
		startTime:    time.Now(),
		messages:     messages,
		cleanup:      func() {},
	}
}

// TestConnectionStateFirstResultCarriesMetadata checks the first Next returns the
// connection metadata (id + address + subprotocol) with HasNext set, keeping the stream
// open.
func TestConnectionStateFirstResultCarriesMetadata(t *testing.T) {
	state := newTestConnectionState("flow:c:1", make(chan ws.InboundMessage, 1))

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

	if meta.RemoteAddr != "127.0.0.1:9200" || meta.Subprotocol != "chat" {
		t.Fatalf("metadata: got remote=%q subprotocol=%q", meta.RemoteAddr, meta.Subprotocol)
	}
}

// TestConnectionStateStreamsInboundAfterMetadata checks subsequent Next calls return the
// inbound messages (type byte prefixed), then end cleanly when the read goroutine closes
// the channel.
func TestConnectionStateStreamsInboundAfterMetadata(t *testing.T) {
	messages := make(chan ws.InboundMessage, 2)

	state := newTestConnectionState("flow:c:2", messages)

	// Drain the metadata result first.
	if meta := state.Next(); meta.IsError || !meta.HasNext {
		t.Fatalf("metadata result: error=%v hasNext=%v", meta.IsError, meta.HasNext)
	}

	messages <- ws.InboundMessage{Binary: false, Data: []byte("hello")}
	messages <- ws.InboundMessage{Binary: true, Data: []byte{0x00, 0xFF}}

	text := state.Next()

	if text.IsError || !text.HasNext {
		t.Fatalf("inbound text: error=%v hasNext=%v", text.IsError, text.HasNext)
	}

	if text.Payload[0] != ws.MessageTypeText || text.Payload[1:] != "hello" {
		t.Fatalf("text payload mismatch: %q", text.Payload)
	}

	binary := state.Next()

	if binary.Payload[0] != ws.MessageTypeBinary || binary.Payload[1:] != string([]byte{0x00, 0xFF}) {
		t.Fatalf("binary payload mismatch: %q", binary.Payload)
	}

	close(messages)

	end := state.Next()

	if end.IsError {
		t.Fatalf("a closed channel must end the stream cleanly, got: %s", end.Payload)
	}

	if end.HasNext {
		t.Fatal("expected the inbound stream to end (HasNext=false) once the channel closes")
	}
}

// TestConnectionStateDeliversBufferedMessageWhenContextDone guards the inbound select:
// when a message is already buffered and the context is also done, Next must still deliver
// the buffered message instead of ending the stream. A plain select over both ready cases
// picks at random and could drop the message; the loop runs enough iterations that the
// pre-fix code would hit the wrong branch and flake.
func TestConnectionStateDeliversBufferedMessageWhenContextDone(t *testing.T) {
	cancelledCtx, cancel := context.WithCancel(context.Background())
	cancel()

	for iteration := 0; iteration < 1000; iteration++ {
		messages := make(chan ws.InboundMessage, 1)
		messages <- ws.InboundMessage{Binary: false, Data: []byte("buffered")}

		state := newTestConnectionState("flow:c:race", messages)
		state.ctx = cancelledCtx
		state.metaSent = true

		result := state.Next()

		if result.IsError {
			t.Fatalf("iteration %d: unexpected error: %s", iteration, result.Payload)
		}

		if !result.HasNext || result.Payload[1:] != "buffered" {
			t.Fatalf(
				"iteration %d: a buffered message must be delivered before the context-done EOF; got hasNext=%v payload=%q",
				iteration,
				result.HasNext,
				result.Payload,
			)
		}
	}
}

// TestConnectionStateEndsStreamOnContextDoneWhenNoMessage confirms a done context still
// ends the stream when nothing is buffered (the caller's read() returns null).
func TestConnectionStateEndsStreamOnContextDoneWhenNoMessage(t *testing.T) {
	cancelledCtx, cancel := context.WithCancel(context.Background())
	cancel()

	state := newTestConnectionState("flow:c:done", make(chan ws.InboundMessage))
	state.ctx = cancelledCtx
	state.metaSent = true

	result := state.Next()

	if result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	if result.HasNext {
		t.Fatal("a done context with no buffered message must end the stream (HasNext=false)")
	}
}

// TestConnectionStateCloseRunsCleanup checks Close invokes the cleanup hook (which the
// feature uses to drop the connection from the registry and close the socket).
func TestConnectionStateCloseRunsCleanup(t *testing.T) {
	cleaned := false

	state := newTestConnectionState("flow:c:3", make(chan ws.InboundMessage))
	state.cleanup = func() {
		cleaned = true
	}

	state.Close()

	if !cleaned {
		t.Fatal("Close must run the cleanup hook")
	}
}
