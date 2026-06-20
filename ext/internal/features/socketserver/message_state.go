package socketserver_feature

import (
	"bufio"
	"errors"
	"io"
	"net"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/helpers"
	"sync"
	"time"
)

var _ contracts.StateContract = (*messageState)(nil)

// messageState streams the inbound length-prefixed frames of one connection to PHP,
// one frame per Next() (modeled on the HTTP request-body state). Each delivered
// frame signals the connection's write loop that a message went into handling, so
// the per-message handler timeout can be armed independently of PHP.
type messageState struct {
	mutex     sync.Mutex
	message   *dto.Message
	conn      net.Conn
	reader    *bufio.Reader
	pending   *pendingConnection
	config    serverConfig
	startTime time.Time
}

func newMessageState(
	message *dto.Message,
	conn net.Conn,
	reader *bufio.Reader,
	pending *pendingConnection,
	config serverConfig,
) *messageState {
	return &messageState{
		message:   message,
		conn:      conn,
		reader:    reader,
		pending:   pending,
		config:    config,
		startTime: time.Now(),
	}
}

func (s *messageState) Next() *dto.Result {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	if s.config.readTimeout > 0 {
		_ = s.conn.SetReadDeadline(time.Now().Add(s.config.readTimeout))
	} else {
		_ = s.conn.SetReadDeadline(time.Time{})
	}

	frame, err := readFrame(s.reader, s.config.maxMessageBytes)

	if err != nil {
		// A clean connection end (EOF, closed read side on drain, idle timeout):
		// end the stream so the PHP loop exits and the connection coroutine finishes
		// without surfacing an error to the handler.
		if isConnectionClosed(err) {
			return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
		}

		return dto.NewErrorResult(s.message, errFactory.ByErr("read frame", err))
	}

	// Tell the write loop a message is now in flight so it can arm the handler
	// timeout (non-blocking, capacity-1 channel: at most one in-flight per conn).
	s.pending.signalMessageStarted()

	return dto.NewSuccessResultWithNext(s.message, string(frame), helpers.CalcExecutionMs(s.startTime))
}

func (s *messageState) Close() {
	// The connection itself is closed by handleConn; nothing to do here.
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
