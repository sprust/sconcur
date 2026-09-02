package logger

import (
	"bytes"
	"testing"
)

func TestWritesQueuedLinesInOrderOnFlush(t *testing.T) {
	buffer := &bytes.Buffer{}

	logger := New(buffer)

	logger.Write("a\n")
	logger.Write("b\n")
	logger.Write("c\n")

	// Flush drains the queue and flushes the buffer, and returns only once that is
	// done — so the buffer holds every line written before the call, in order.
	logger.Flush()

	if got := buffer.String(); got != "a\nb\nc\n" {
		t.Fatalf("unexpected log output: %q", got)
	}
}

func TestFlushReportsDroppedLines(t *testing.T) {
	buffer := &bytes.Buffer{}

	logger := New(buffer)

	// Simulate a full-queue drop, then flush: the dropped count is reported.
	logger.dropped = 5

	logger.Flush()

	if got := buffer.String(); got != "[logger] dropped 5 line(s)\n" {
		t.Fatalf("expected a drop report, got: %q", got)
	}
}
