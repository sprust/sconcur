package socketserver_feature

import (
	"encoding/binary"
	"errors"
	"io"
)

// frameLengthSize is the fixed size of the length prefix: a uint32 big-endian byte
// count of the payload that follows.
const frameLengthSize = 4

// errFrameTooLarge is returned when an inbound frame's declared length exceeds
// maxMessageBytes, so a malicious or buggy peer cannot make the server allocate an
// arbitrarily large buffer.
var errFrameTooLarge = errors.New("message frame exceeds maxMessageBytes")

// readFrame reads one length-prefixed frame: a uint32 big-endian length followed by
// that many payload bytes. A clean io.EOF is returned only when the stream ends
// exactly on a frame boundary (before any header byte); a mid-frame end surfaces as
// io.ErrUnexpectedEOF. maxBytes <= 0 means no limit.
func readFrame(reader io.Reader, maxBytes int) ([]byte, error) {
	var header [frameLengthSize]byte

	if _, err := io.ReadFull(reader, header[:]); err != nil {
		return nil, err
	}

	length := int(binary.BigEndian.Uint32(header[:]))

	if maxBytes > 0 && length > maxBytes {
		return nil, errFrameTooLarge
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

// writeFrame writes one length-prefixed frame in a single Write call (length prefix
// + payload), so a frame is never split into two writes on the wire.
func writeFrame(writer io.Writer, payload []byte) error {
	buffer := make([]byte, frameLengthSize+len(payload))

	binary.BigEndian.PutUint32(buffer[:frameLengthSize], uint32(len(payload)))
	copy(buffer[frameLengthSize:], payload)

	_, err := writer.Write(buffer)

	return err
}
