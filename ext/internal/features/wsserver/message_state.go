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
	ctx       context.Context
	message   *dto.Message
	messages  chan ws.InboundMessage
	drain     chan struct{}
	startTime time.Time
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
	select {
	case message, ok := <-s.messages:
		if !ok {
			// The read goroutine ended (peer closed, oversize message, idle timeout,
			// shutdown): end the stream so the handler's read() returns null.
			return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
		}

		return dto.NewSuccessResultWithNext(s.message, ws.EncodeInbound(message), helpers.CalcExecutionMs(s.startTime))
	case <-s.drain:
		// Draining: stop delivering input so the handler ends, like a read half-close.
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	case <-s.ctx.Done():
		// Server stopped: end the stream so the handler's read() returns null.
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}
}

func (s *messageState) Close() {
	// The connection and its read goroutine are owned by serverState.handleConn; nothing
	// to do here.
}
