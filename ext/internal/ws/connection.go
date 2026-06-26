// Package ws holds the neutral WebSocket plumbing shared by the ws server (accept-side)
// and ws client (dial-side): the per-connection write loop, the write/close command
// rendezvous with backpressure, and the inbound message-type codec. Like internal/socket
// for the raw TCP features, it is depended on by both ws features but not by each other.
package ws

import (
	"context"
	"errors"
	"time"

	"github.com/coder/websocket"
)

// WriteKind tags what an action does to a connection. OpFrame writes one message to the
// peer; OpClose closes the connection. The numeric values are part of the PHP↔Go
// protocol (the RespondPayload/SendParams op field).
type WriteKind int

const (
	OpFrame WriteKind = 0
	OpClose WriteKind = 1
)

// WriteCommand is one action a PHP handler performs on its connection: write a message
// (with its WebSocket message type) to the peer, or close the connection. Done carries
// the result of applying it back to the issuing coroutine (nil on success, an error if
// the write failed) so the handler gets real write backpressure.
type WriteCommand struct {
	Kind        WriteKind
	MessageType websocket.MessageType
	Data        []byte
	Done        chan error
}

// PendingConnection is the rendezvous between the connection's write loop and the PHP
// handler's write/close commands. Abandoned is closed once the write loop stops
// consuming (shutdown or the connection is gone) so a handler that writes late unblocks
// with an error instead of hanging on the Commands channel.
type PendingConnection struct {
	Conn      *websocket.Conn
	Commands  chan WriteCommand
	Abandoned chan struct{}
}

// ErrAbandoned is returned to a handler coroutine when the write loop has stopped
// consuming its commands (shutdown or the connection is gone), so the coroutine unwinds
// instead of blocking on the Commands channel.
var ErrAbandoned = errors.New("connection abandoned")

// ConsumeCommands runs one connection's write loop: it applies the handler's actions
// (write a message, or close) and — when pingInterval > 0 — sends a keepalive ping on
// that cadence, until the handler closes the connection or ctx is done. Each command's
// outcome is reported on its Done channel so the issuing coroutine gets write
// backpressure. Returns the number of messages written and a status string for the
// access log.
func ConsumeCommands(
	ctx context.Context,
	pending *PendingConnection,
	writeTimeout time.Duration,
	pingInterval time.Duration,
) (int, string) {
	messageCount := 0
	status := "ok"

	var pingTick <-chan time.Time

	if pingInterval > 0 {
		ticker := time.NewTicker(pingInterval)
		defer ticker.Stop()

		pingTick = ticker.C
	}

	for {
		select {
		case <-ctx.Done():
			return messageCount, "shutdown"
		case <-pingTick:
			pingCtx, cancel := context.WithTimeout(ctx, writeTimeout)
			err := pending.Conn.Ping(pingCtx)
			cancel()

			if err != nil {
				// No pong within the deadline (or the connection is gone): the peer is
				// dead, so end the connection.
				return messageCount, "ping_failed"
			}
		case command := <-pending.Commands:
			if command.Kind == OpClose {
				command.Done <- nil
				_ = pending.Conn.Close(websocket.StatusNormalClosure, "")

				return messageCount, status
			}

			writeCtx, cancel := context.WithTimeout(ctx, writeTimeout)
			err := pending.Conn.Write(writeCtx, command.MessageType, command.Data)
			cancel()

			command.Done <- err

			if err != nil {
				return messageCount, "write_error"
			}

			messageCount++
		}
	}
}

// Dispatch hands one write command to the connection's write loop and waits for it to
// be applied, so the handler coroutine only continues once the message hits the wire
// (write backpressure). It returns the write error, if any — including ErrAbandoned when
// the write loop has stopped consuming (shutdown or the connection is gone), so the
// handler coroutine unwinds instead of hanging.
func Dispatch(ctx context.Context, pending *PendingConnection, command WriteCommand) error {
	command.Done = make(chan error, 1)

	select {
	case pending.Commands <- command:
	case <-pending.Abandoned:
		return ErrAbandoned
	case <-ctx.Done():
		return nil
	}

	// Prefer a delivered result over a late abandon signal: if the write was applied,
	// honor it even if the write loop returned right after.
	select {
	case err := <-command.Done:
		return err
	default:
	}

	select {
	case err := <-command.Done:
		return err
	case <-pending.Abandoned:
		return ErrAbandoned
	case <-ctx.Done():
		return nil
	}
}
