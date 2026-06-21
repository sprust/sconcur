package socket

import (
	"context"
	"errors"
	"net"
	"strconv"
	"sync/atomic"
	"time"
)

// WriteKind tags what an action does to a connection. OpFrame writes one
// length-prefixed frame to the peer; OpClose closes the connection. The numeric
// values are part of the PHP↔Go protocol (RespondPayload/SendPayload op field).
type WriteKind int

const (
	OpFrame WriteKind = 0
	OpClose WriteKind = 1
)

// WriteCommand is one action a PHP handler performs on its connection. Done carries
// the result of applying it back to the issuing coroutine (nil on success, an error
// if the write failed) so the handler gets real write backpressure and learns about
// a dead connection.
type WriteCommand struct {
	Kind WriteKind
	Data string
	Done chan error
}

// PendingConnection is the rendezvous between the connection goroutine (the write
// loop) and the PHP handler's write/close commands. Abandoned is closed once the
// write loop stops consuming (shutdown or the connection is gone) so a handler that
// writes late unblocks with an error instead of hanging on the Commands channel.
type PendingConnection struct {
	Conn      net.Conn
	Commands  chan WriteCommand
	Abandoned chan struct{}
}

// CloseRead closes only the read side of the connection (graceful drain): blocked
// reads return EOF so an idle handler loop ends, while an in-flight write can still
// go through. Falls back to a full close if half-close is unavailable.
func (p *PendingConnection) CloseRead() {
	if tcpConn, ok := p.Conn.(*net.TCPConn); ok {
		_ = tcpConn.CloseRead()

		return
	}

	_ = p.Conn.Close()
}

// ErrAbandoned is returned to a handler coroutine when the write loop has stopped
// consuming its commands (shutdown or the connection is gone), so the coroutine
// unwinds instead of blocking on the Commands channel.
var ErrAbandoned = errors.New("connection abandoned")

// ConsumeCommands runs one connection's write loop: it applies the handler's actions
// (write a frame, or close) until the handler closes the connection or ctx is done.
// Each command's outcome is reported on its Done channel so the issuing coroutine
// gets write backpressure. Returns the number of frames written and a status string
// for the access log.
func ConsumeCommands(ctx context.Context, pending *PendingConnection, writeTimeout time.Duration) (int, string) {
	frameCount := 0
	status := "ok"

	for {
		select {
		case <-ctx.Done():
			return frameCount, "shutdown"
		case command := <-pending.Commands:
			if command.Kind == OpClose {
				command.Done <- nil

				return frameCount, status
			}

			err := writeFrameWithDeadline(pending.Conn, command.Data, writeTimeout)
			command.Done <- err

			if err != nil {
				return frameCount, "write_error"
			}

			frameCount++
		}
	}
}

// writeFrameWithDeadline writes one length-prefixed frame to the peer, bounded by
// writeTimeout (0 = no deadline).
func writeFrameWithDeadline(conn net.Conn, data string, writeTimeout time.Duration) error {
	if writeTimeout > 0 {
		_ = conn.SetWriteDeadline(time.Now().Add(writeTimeout))
	}

	return WriteFrame(conn, []byte(data))
}

// Dispatch hands one write command to the connection's write loop and waits for it
// to be applied, so the handler coroutine only continues once the bytes hit the wire
// (write backpressure). It returns the write error, if any — including ErrAbandoned
// when the write loop has stopped consuming (shutdown or the connection is gone), so
// the handler coroutine unwinds instead of hanging.
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

// connectionCounter backs NextConnectionId. Shared across server and client so a
// connection id is globally unique within the process.
var connectionCounter atomic.Int64

// NextConnectionId mints a unique connection id scoped to a flow: "<flowKey>:c:<n>".
func NextConnectionId(flowKey string) string {
	return flowKey + ":c:" + strconv.FormatInt(connectionCounter.Add(1), 10)
}
