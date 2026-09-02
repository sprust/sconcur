package wsserver_feature

import (
	"context"
	"testing"
	"time"

	"sconcur/internal/dto"
	"sconcur/internal/ws"
)

// raceIterations is large enough that a select picking a ready case at random would, with
// near-certainty, hit the wrong branch at least once across the loop — so these tests fail
// on the pre-fix code and pass deterministically once buffered messages get priority.
const raceIterations = 1000

// TestMessageStateDeliversBufferedMessageWhenDraining guards the inbound select: when a
// message is already buffered and drain is also closed, Next must still deliver the
// buffered message instead of half-closing. A plain three-way select over both ready cases
// picks at random and could drop the message — the bug behind the flaky maxConnections
// test, where the limiting connection's "ping" raced the drain signal and never got its
// "pong".
func TestMessageStateDeliversBufferedMessageWhenDraining(t *testing.T) {
	drain := make(chan struct{})
	close(drain)

	for iteration := 0; iteration < raceIterations; iteration++ {
		messages := make(chan ws.InboundMessage, 1)
		messages <- ws.InboundMessage{Binary: false, Data: []byte("ping")}

		state := newMessageState(context.Background(), &dto.Message{}, messages, drain)

		result := state.Next()

		if result.IsError {
			t.Fatalf("iteration %d: unexpected error: %s", iteration, result.Payload)
		}

		if !result.HasNext || result.Payload[1:] != "ping" {
			t.Fatalf(
				"iteration %d: a buffered message must be delivered before the drain half-close; got hasNext=%v payload=%q",
				iteration,
				result.HasNext,
				result.Payload,
			)
		}
	}
}

// TestMessageStateDeliversBufferedMessageWhenContextDone is the same guard for the
// server-stopped (ctx) branch of the select.
func TestMessageStateDeliversBufferedMessageWhenContextDone(t *testing.T) {
	cancelledCtx, cancel := context.WithCancel(context.Background())
	cancel()

	for iteration := 0; iteration < raceIterations; iteration++ {
		messages := make(chan ws.InboundMessage, 1)
		messages <- ws.InboundMessage{Binary: true, Data: []byte{0x01, 0x02}}

		state := newMessageState(cancelledCtx, &dto.Message{}, messages, make(chan struct{}))

		result := state.Next()

		if result.IsError {
			t.Fatalf("iteration %d: unexpected error: %s", iteration, result.Payload)
		}

		if !result.HasNext || result.Payload[0] != ws.MessageTypeBinary {
			t.Fatalf(
				"iteration %d: a buffered message must be delivered before the context-done EOF; got hasNext=%v payload=%q",
				iteration,
				result.HasNext,
				result.Payload,
			)
		}
	}
}

// TestMessageStateEndsStreamOnDrainWhenNoMessage confirms drain still half-closes the
// stream when nothing is buffered and nothing arrives, so an idle handler's read()
// returns null (after the bounded first-message window, since nothing was delivered).
func TestMessageStateEndsStreamOnDrainWhenNoMessage(t *testing.T) {
	drain := make(chan struct{})
	close(drain)

	state := newMessageState(context.Background(), &dto.Message{}, make(chan ws.InboundMessage), drain)

	result := state.Next()

	if result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	if result.HasNext {
		t.Fatal("draining with no buffered message must end the stream (HasNext=false)")
	}
}

// TestMessageStateWaitsForFirstMessageAfterDrain guards the second race window of the
// flaky maxConnections test: the limiting connection is drained right after dispatch,
// and its opening message may still be on the wire when the handler's read() reaches
// Next(). A connection that has not delivered anything yet must give that first
// message a bounded window (firstMessageDrainGrace) instead of returning EOF at once.
func TestMessageStateWaitsForFirstMessageAfterDrain(t *testing.T) {
	drain := make(chan struct{})
	close(drain)

	messages := make(chan ws.InboundMessage, 1)

	state := newMessageState(context.Background(), &dto.Message{}, messages, drain)

	go func() {
		time.Sleep(50 * time.Millisecond)

		messages <- ws.InboundMessage{Binary: false, Data: []byte("ping")}
	}()

	result := state.Next()

	if result.IsError {
		t.Fatalf("unexpected error: %s", result.Payload)
	}

	if !result.HasNext || result.Payload[1:] != "ping" {
		t.Fatalf(
			"the first in-flight message must be delivered within the drain window; got hasNext=%v payload=%q",
			result.HasNext,
			result.Payload,
		)
	}
}

// TestMessageStateImmediateEofOnDrainAfterFirstDelivery confirms the window applies
// only to a connection's first message: once something was delivered, drain keeps the
// immediate half-close so shutdown stays fast for long-lived connections.
func TestMessageStateImmediateEofOnDrainAfterFirstDelivery(t *testing.T) {
	drain := make(chan struct{})

	messages := make(chan ws.InboundMessage, 1)
	messages <- ws.InboundMessage{Binary: false, Data: []byte("ping")}

	state := newMessageState(context.Background(), &dto.Message{}, messages, drain)

	if first := state.Next(); !first.HasNext {
		t.Fatal("the buffered first message must be delivered")
	}

	close(drain)

	eofStartTime := time.Now()

	result := state.Next()

	if result.HasNext {
		t.Fatal("after the first delivery, drain must end the stream (HasNext=false)")
	}

	if elapsed := time.Since(eofStartTime); elapsed >= firstMessageDrainGrace {
		t.Fatalf("the drain EOF must be immediate after the first delivery, took %s", elapsed)
	}
}
