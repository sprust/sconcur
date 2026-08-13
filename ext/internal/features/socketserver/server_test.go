package socketserver_feature

import (
	"context"
	"net"
	"testing"
	"time"

	"sconcur/internal/dto"
	"sconcur/internal/features/socketserver/payloads"
)

// A second Close (the flow-context AfterFunc plus an explicit call) must be a
// no-op: pusher.Stop would panic on a second stop without the closeOnce guard.
func TestCloseIsIdempotent(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	message := &dto.Message{FlowKey: "close-flow", TaskKey: "close-task"}

	state := newServerState(ctx, message, listener, time.Now(), configFromPayload(payloads.ServePayload{}))

	state.Close()
	state.Close()
}
