package socketserver_feature

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

		if err := writeFrame(&buffer, payload); err != nil {
			t.Fatalf("writeFrame: %v", err)
		}

		got, err := readFrame(&buffer, 0)

		if err != nil {
			t.Fatalf("readFrame: %v", err)
		}

		if !bytes.Equal(got, payload) {
			t.Fatalf("round trip mismatch: got %d bytes, want %d bytes", len(got), len(payload))
		}
	}
}

func TestReadFrameRejectsOversizeLength(t *testing.T) {
	var buffer bytes.Buffer

	if err := writeFrame(&buffer, bytes.Repeat([]byte("x"), 100)); err != nil {
		t.Fatalf("writeFrame: %v", err)
	}

	_, err := readFrame(&buffer, 16)

	if !errors.Is(err, errFrameTooLarge) {
		t.Fatalf("expected errFrameTooLarge, got %v", err)
	}
}

func TestReadFrameCleanEofOnFrameBoundary(t *testing.T) {
	_, err := readFrame(bytes.NewReader(nil), 0)

	if !errors.Is(err, io.EOF) {
		t.Fatalf("expected io.EOF on an empty stream, got %v", err)
	}
}

func TestReadFrameUnexpectedEofMidFrame(t *testing.T) {
	// A header announcing 10 bytes but only 4 present: a truncated frame.
	header := make([]byte, frameLengthSize)
	binary.BigEndian.PutUint32(header, 10)

	reader := bytes.NewReader(append(header, []byte("abcd")...))

	_, err := readFrame(reader, 0)

	if !errors.Is(err, io.ErrUnexpectedEOF) {
		t.Fatalf("expected io.ErrUnexpectedEOF mid-frame, got %v", err)
	}
}

func TestReadFrameSplitsConcatenatedFrames(t *testing.T) {
	var buffer bytes.Buffer

	_ = writeFrame(&buffer, []byte("first"))
	_ = writeFrame(&buffer, []byte("second"))

	first, err := readFrame(&buffer, 0)

	if err != nil || string(first) != "first" {
		t.Fatalf("first frame: got %q, err %v", first, err)
	}

	second, err := readFrame(&buffer, 0)

	if err != nil || string(second) != "second" {
		t.Fatalf("second frame: got %q, err %v", second, err)
	}
}
