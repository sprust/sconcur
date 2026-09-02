package socket

import (
	"bufio"
	"errors"
	"io"
	"net"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/helpers"
	"sync"
	"time"
)

var _ contracts.StateContract = (*MessageState)(nil)

// MessageState streams the inbound length-prefixed frames of one connection to PHP,
// one frame per Next(). Shared by the server (the handler reads via
// Connection::read()) and the client (the same, dial-side). Implements
// contracts.StateContract.
type MessageState struct {
	mutex           sync.Mutex
	message         *dto.Message
	conn            net.Conn
	reader          *bufio.Reader
	readTimeout     time.Duration
	maxMessageBytes int
	errFactory      *errs.Factory
	startTime       time.Time
}

// NewMessageState builds the inbound frame stream for a connection. readTimeout 0
// means no idle deadline; maxMessageBytes 0 means no inbound frame size limit.
func NewMessageState(
	message *dto.Message,
	conn net.Conn,
	reader *bufio.Reader,
	readTimeout time.Duration,
	maxMessageBytes int,
	errFactory *errs.Factory,
) *MessageState {
	return &MessageState{
		message:         message,
		conn:            conn,
		reader:          reader,
		readTimeout:     readTimeout,
		maxMessageBytes: maxMessageBytes,
		errFactory:      errFactory,
		startTime:       time.Now(),
	}
}

func (s *MessageState) Next() *dto.Result {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	if s.readTimeout > 0 {
		_ = s.conn.SetReadDeadline(time.Now().Add(s.readTimeout))
	} else {
		_ = s.conn.SetReadDeadline(time.Time{})
	}

	frame, err := ReadFrame(s.reader, s.maxMessageBytes)

	if err != nil {
		// A clean connection end (EOF, closed read side on drain, idle timeout):
		// end the stream so the PHP loop exits and the connection coroutine finishes
		// without surfacing an error to the handler.
		if isConnectionClosed(err) {
			return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
		}

		return dto.NewErrorResult(s.message, s.errFactory.ByErr("read frame", err))
	}

	return dto.NewSuccessResultWithNext(s.message, string(frame), helpers.CalcExecutionMs(s.startTime))
}

func (s *MessageState) Close() {
	// The connection itself is closed by its owner (the server's handleConn or the
	// client's connection cleanup); nothing to do here.
}

// isConnectionClosed reports whether a read error means the connection has ended
// normally (peer closed, our own read side closed on drain, or the idle read
// deadline elapsed) — all of which finish the stream cleanly rather than as an
// error the handler must see.
func isConnectionClosed(err error) bool {
	if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) || errors.Is(err, net.ErrClosed) {
		return true
	}

	var netErr net.Error

	if errors.As(err, &netErr) && netErr.Timeout() {
		return true
	}

	return false
}
