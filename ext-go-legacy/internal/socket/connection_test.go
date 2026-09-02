package socket

import (
	"context"
	"errors"
	"testing"
	"time"
)

func TestConsumeCommandsWritesFramesThenCloses(t *testing.T) {
	client, server := dialPair(t)

	pending := &PendingConnection{
		Conn:      client,
		Commands:  make(chan WriteCommand),
		Abandoned: make(chan struct{}),
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	loopDone := make(chan struct{})

	go func() {
		ConsumeCommands(ctx, pending, time.Second)

		close(pending.Abandoned)
		close(loopDone)
	}()

	if err := Dispatch(ctx, pending, WriteCommand{Kind: OpFrame, Data: "first"}); err != nil {
		t.Fatalf("dispatch first frame: %v", err)
	}

	if err := Dispatch(ctx, pending, WriteCommand{Kind: OpFrame, Data: "second"}); err != nil {
		t.Fatalf("dispatch second frame: %v", err)
	}

	first, err := ReadFrame(server, 0)

	if err != nil || string(first) != "first" {
		t.Fatalf("first frame: got %q, err %v", first, err)
	}

	second, err := ReadFrame(server, 0)

	if err != nil || string(second) != "second" {
		t.Fatalf("second frame: got %q, err %v", second, err)
	}

	if err := Dispatch(ctx, pending, WriteCommand{Kind: OpClose}); err != nil {
		t.Fatalf("dispatch close: %v", err)
	}

	select {
	case <-loopDone:
	case <-time.After(time.Second):
		t.Fatal("the write loop did not stop after a close command")
	}
}

func TestDispatchReturnsErrAbandonedWhenLoopStopped(t *testing.T) {
	client, _ := dialPair(t)

	pending := &PendingConnection{
		Conn:      client,
		Commands:  make(chan WriteCommand),
		Abandoned: make(chan struct{}),
	}

	// The write loop has already stopped (its commands are no longer consumed).
	close(pending.Abandoned)

	err := Dispatch(context.Background(), pending, WriteCommand{Kind: OpFrame, Data: "late"})

	if !errors.Is(err, ErrAbandoned) {
		t.Fatalf("expected ErrAbandoned, got %v", err)
	}
}

func TestConsumeCommandsStopsOnContextCancel(t *testing.T) {
	client, _ := dialPair(t)

	pending := &PendingConnection{
		Conn:      client,
		Commands:  make(chan WriteCommand),
		Abandoned: make(chan struct{}),
	}

	ctx, cancel := context.WithCancel(context.Background())

	loopDone := make(chan struct{})

	go func() {
		_, status := ConsumeCommands(ctx, pending, time.Second)

		if status != "shutdown" {
			t.Errorf("status: got %q, want %q", status, "shutdown")
		}

		close(loopDone)
	}()

	cancel()

	select {
	case <-loopDone:
	case <-time.After(time.Second):
		t.Fatal("the write loop did not stop on context cancel")
	}
}

func TestNextConnectionIdIsUniqueAndScoped(t *testing.T) {
	first := NextConnectionId("flow")
	second := NextConnectionId("flow")

	if first == second {
		t.Fatalf("ids must be unique, got %q twice", first)
	}

	for _, id := range []string{first, second} {
		if len(id) <= len("flow:c:") || id[:len("flow:c:")] != "flow:c:" {
			t.Fatalf("id %q is not scoped to the flow", id)
		}
	}
}
