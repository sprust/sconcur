// Package socket holds the length-prefix framing and per-connection I/O shared by
// the socket server (accept-side) and the socket client (dial-side). It is neutral
// infrastructure with no Method of its own; both features depend on it, not on each
// other.
package socket

import (
	"encoding/binary"
	"errors"
	"io"
)

// FrameLengthSize is the fixed size of the length prefix: a uint32 big-endian byte
// count of the payload that follows.
const FrameLengthSize = 4

// ErrFrameTooLarge is returned when an inbound frame's declared length exceeds
// maxMessageBytes, so a malicious or buggy peer cannot make us allocate an
// arbitrarily large buffer.
var ErrFrameTooLarge = errors.New("message frame exceeds maxMessageBytes")

// ReadFrame reads one length-prefixed frame: a uint32 big-endian length followed by
// that many payload bytes. A clean io.EOF is returned only when the stream ends
// exactly on a frame boundary (before any header byte); a mid-frame end surfaces as
// io.ErrUnexpectedEOF. maxBytes <= 0 means no limit.
func ReadFrame(reader io.Reader, maxBytes int) ([]byte, error) {
	var header [FrameLengthSize]byte

	if _, err := io.ReadFull(reader, header[:]); err != nil {
		return nil, err
	}

	// Keep the length unsigned and compare in the uint domain: on a 32-bit build a
	// length with the high bit set would otherwise become a negative int, slip past
	// the size check, and panic make() — a remote crash from a crafted prefix.
	length := binary.BigEndian.Uint32(header[:])

	if maxBytes > 0 && uint64(length) > uint64(maxBytes) {
		return nil, ErrFrameTooLarge
	}

	if length == 0 {
		return []byte{}, nil
	}

	payload := make([]byte, length)

	if _, err := io.ReadFull(reader, payload); err != nil {
		return nil, err
	}

	return payload, nil
}

// WriteFrame writes one length-prefixed frame in a single Write call (length prefix
// + payload), so a frame is never split into two writes on the wire.
func WriteFrame(writer io.Writer, payload []byte) error {
	buffer := make([]byte, FrameLengthSize+len(payload))

	binary.BigEndian.PutUint32(buffer[:FrameLengthSize], uint32(len(payload)))
	copy(buffer[FrameLengthSize:], payload)

	_, err := writer.Write(buffer)

	return err
}
