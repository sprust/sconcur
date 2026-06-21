package socket

import (
	"bytes"
	"encoding/binary"
	"errors"
	"io"
	"testing"
)

func TestWriteFrameThenReadFrameRoundTrips(t *testing.T) {
	cases := [][]byte{
		[]byte("hello"),
		{},
		{0x00, 0x0a, 0xff, 0x00},
		bytes.Repeat([]byte("x"), 70000),
	}

	for _, payload := range cases {
		var buffer bytes.Buffer

		if err := WriteFrame(&buffer, payload); err != nil {
			t.Fatalf("WriteFrame: %v", err)
		}

		got, err := ReadFrame(&buffer, 0)

		if err != nil {
			t.Fatalf("ReadFrame: %v", err)
		}

		if !bytes.Equal(got, payload) {
			t.Fatalf("round trip mismatch: got %d bytes, want %d bytes", len(got), len(payload))
		}
	}
}

func TestReadFrameRejectsOversizeLength(t *testing.T) {
	var buffer bytes.Buffer

	if err := WriteFrame(&buffer, bytes.Repeat([]byte("x"), 100)); err != nil {
		t.Fatalf("WriteFrame: %v", err)
	}

	_, err := ReadFrame(&buffer, 16)

	if !errors.Is(err, ErrFrameTooLarge) {
		t.Fatalf("expected ErrFrameTooLarge, got %v", err)
	}
}

func TestReadFrameRejectsHighBitLengthWithoutPanicking(t *testing.T) {
	// A length prefix with the high bit set (0x80000000) must be rejected by the
	// size check, not become a negative int on a 32-bit build and panic make().
	header := make([]byte, FrameLengthSize)
	binary.BigEndian.PutUint32(header, 0x80000000)

	_, err := ReadFrame(bytes.NewReader(header), 16)

	if !errors.Is(err, ErrFrameTooLarge) {
		t.Fatalf("expected ErrFrameTooLarge for an oversize high-bit length, got %v", err)
	}
}

func TestReadFrameCleanEofOnFrameBoundary(t *testing.T) {
	_, err := ReadFrame(bytes.NewReader(nil), 0)

	if !errors.Is(err, io.EOF) {
		t.Fatalf("expected io.EOF on an empty stream, got %v", err)
	}
}

func TestReadFrameUnexpectedEofMidFrame(t *testing.T) {
	// A header announcing 10 bytes but only 4 present: a truncated frame.
	header := make([]byte, FrameLengthSize)
	binary.BigEndian.PutUint32(header, 10)

	reader := bytes.NewReader(append(header, []byte("abcd")...))

	_, err := ReadFrame(reader, 0)

	if !errors.Is(err, io.ErrUnexpectedEOF) {
		t.Fatalf("expected io.ErrUnexpectedEOF mid-frame, got %v", err)
	}
}

func TestReadFrameSplitsConcatenatedFrames(t *testing.T) {
	var buffer bytes.Buffer

	_ = WriteFrame(&buffer, []byte("first"))
	_ = WriteFrame(&buffer, []byte("second"))

	first, err := ReadFrame(&buffer, 0)

	if err != nil || string(first) != "first" {
		t.Fatalf("first frame: got %q, err %v", first, err)
	}

	second, err := ReadFrame(&buffer, 0)

	if err != nil || string(second) != "second" {
		t.Fatalf("second frame: got %q, err %v", second, err)
	}
}
