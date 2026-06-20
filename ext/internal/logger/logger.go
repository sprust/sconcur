// Package logger is a fire-and-forget asynchronous log sink. Callers compose a log
// line (e.g. the HttpServer access-log line emitted from the Go response goroutine)
// and hand it to Write; the actual write happens here, off the caller's thread, in a
// background goroutine. This keeps PHP's single-threaded cooperative loop from ever
// blocking on log I/O (no synchronous fwrite/fflush per request).
package logger

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"os/signal"
	"sync/atomic"
	"syscall"
	"time"
)

const (
	// queueSize bounds memory: if logging outpaces the writer the oldest policy is
	// to drop new lines (counted) rather than block the producer (PHP).
	queueSize = 4096

	// flushInterval bounds how long a line can sit buffered before it is visible
	// (and bounds loss on a crash). Access logs do not need per-line flushing.
	flushInterval = 100 * time.Millisecond
)

// Logger drains a bounded channel of pre-formatted lines into a buffered writer.
type Logger struct {
	queue    chan string
	flushReq chan chan struct{}
	dropped  int64
}

// std is the process logger, writing to stdout. Under WorkerMaster the worker's
// stdout is a pipe the master drains and rotates; under docker/systemd the platform
// collects it.
var std = newStd()

func newStd() *Logger {
	// A broken stdout pipe (e.g. the WorkerMaster died) must not kill the process
	// with SIGPIPE; with this, writes to a dead pipe return EPIPE and are dropped.
	signal.Ignore(syscall.SIGPIPE)

	return New(os.Stdout)
}

// New starts a logger writing to out and returns it. Exposed for tests.
func New(out io.Writer) *Logger {
	logger := &Logger{
		queue:    make(chan string, queueSize),
		flushReq: make(chan chan struct{}),
	}

	go logger.run(out)

	return logger
}

// Write enqueues a line (which should already carry its trailing newline) for the
// background writer. Non-blocking: if the queue is full the line is dropped and
// counted, so the caller (PHP) never stalls on logging.
func Write(line string) {
	std.Write(line)
}

// Flush writes out everything queued so far and flushes the buffer. Called on
// destroy so buffered lines are not lost on shutdown.
func Flush() {
	std.Flush()
}

func (logger *Logger) Write(line string) {
	select {
	case logger.queue <- line:
	default:
		atomic.AddInt64(&logger.dropped, 1)
	}
}

func (logger *Logger) Flush() {
	done := make(chan struct{})

	logger.flushReq <- done

	<-done
}

func (logger *Logger) run(out io.Writer) {
	writer := bufio.NewWriter(out)

	ticker := time.NewTicker(flushInterval)

	defer ticker.Stop()

	for {
		select {
		case line := <-logger.queue:
			io.WriteString(writer, line)

		case <-ticker.C:
			logger.flush(writer)

		case done := <-logger.flushReq:
			logger.drain(writer)
			logger.flush(writer)

			close(done)
		}
	}
}

// drain writes every line currently queued without blocking.
func (logger *Logger) drain(writer *bufio.Writer) {
	for {
		select {
		case line := <-logger.queue:
			io.WriteString(writer, line)

		default:
			return
		}
	}
}

func (logger *Logger) flush(writer *bufio.Writer) {
	if dropped := atomic.SwapInt64(&logger.dropped, 0); dropped > 0 {
		fmt.Fprintf(writer, "[logger] dropped %d line(s)\n", dropped)
	}

	if writer.Buffered() > 0 {
		// Ignore write errors (e.g. EPIPE on a dead stdout pipe): logging must never
		// crash or stall the server.
		_ = writer.Flush()
	}
}
