package wsserver_feature

import (
	"context"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/helpers"
	"sconcur/internal/ws"
	"time"
)

var _ contracts.StateContract = (*messageState)(nil)

// firstMessageDrainGrace is how long Next() still waits for the connection's FIRST
// inbound message after the drain signal fired. The limiting maxConnections
// connection is dispatched and drained almost simultaneously, so its first message
// may still be on the wire when drain closes; without this window the handler gets
// EOF and that request is silently bounced (the flaky maxConnections test failure).
// Connections that have already delivered a message keep the immediate EOF.
const firstMessageDrainGrace = 250 * time.Millisecond

// messageState streams the inbound data messages of one connection to PHP, one message
// per Next(). The connection is pumped by a dedicated read goroutine
// (serverState.readLoop) — so control frames (ping/pong/close) are always processed even
// when the handler is push-only and never reads — which feeds data messages here through
// the messages channel. Implements contracts.StateContract.
//
// A closed drain channel (graceful shutdown) ends the stream early so a handler blocked
// on read() unwinds with EOF while it can still write a final message, the WebSocket
// mirror of the socket server's read half-close.
type messageState struct {
	ctx            context.Context
	message        *dto.Message
	messages       chan ws.InboundMessage
	drain          chan struct{}
	startTime      time.Time
	deliveredCount int
}

func newMessageState(
	ctx context.Context,
	message *dto.Message,
	messages chan ws.InboundMessage,
	drain chan struct{},
) *messageState {
	return &messageState{
		ctx:       ctx,
		message:   message,
		messages:  messages,
		drain:     drain,
		startTime: time.Now(),
	}
}

func (s *messageState) Next() *dto.Result {
	// Prefer an already-received message over draining/shutdown. With a buffered message
	// and drain (or ctx) both ready, a plain three-way select would pick a case at random
	// and could drop the buffered message, returning EOF while data was waiting. A
	// non-blocking check first guarantees a message that arrived before the drain signal
	// is still delivered — the half-close semantic: stop new input, but flush what was
	// already read so the handler can answer it before unwinding.
	select {
	case message, ok := <-s.messages:
		return s.resultFromMessage(message, ok)
	default:
	}

	select {
	case message, ok := <-s.messages:
		return s.resultFromMessage(message, ok)
	case <-s.drain:
		// Draining: stop delivering input so the handler ends, like a read half-close.
		// A connection that has not delivered anything yet first gets a bounded
		// window for its in-flight opening message: the limiting maxConnections
		// connection is drained right after dispatch, and cutting its input before
		// that message lands would bounce the very request it was accepted for.
		if s.deliveredCount == 0 {
			if result := s.waitFirstMessage(); result != nil {
				return result
			}
		}

		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	case <-s.ctx.Done():
		// Server stopped: end the stream so the handler's read() returns null.
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}
}

// waitFirstMessage gives the connection's first inbound message a bounded chance
// (firstMessageDrainGrace) to arrive after the drain signal; nil means the window
// elapsed with nothing to deliver and the caller should return the EOF half-close.
func (s *messageState) waitFirstMessage() *dto.Result {
	timer := time.NewTimer(firstMessageDrainGrace)

	defer timer.Stop()

	select {
	case message, ok := <-s.messages:
		return s.resultFromMessage(message, ok)
	case <-timer.C:
		return nil
	}
}

// resultFromMessage turns a receive from the messages channel into a Next() result: a
// delivered message (more to come), or end-of-stream when the channel is closed (the read
// goroutine ended: peer closed, oversize message, idle timeout, shutdown).
func (s *messageState) resultFromMessage(message ws.InboundMessage, ok bool) *dto.Result {
	if !ok {
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}

	s.deliveredCount++

	return dto.NewSuccessResultWithNext(s.message, ws.EncodeInbound(message), helpers.CalcExecutionMs(s.startTime))
}

func (s *messageState) Close() {
	// The connection and its read goroutine are owned by serverState.handleConn; nothing
	// to do here.
}
