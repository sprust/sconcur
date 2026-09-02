package helpers

import (
	"errors"
	"io"
)

// ReadChunk reads up to size bytes from reader. eof is true once the reader is
// exhausted (the returned chunk may still hold the last bytes). A non-nil err is
// a read failure (e.g. a body that exceeded a configured limit). Shared by the
// HTTP-server and HTTP-client features to stream bodies to PHP without buffering
// them whole.
func ReadChunk(reader io.Reader, size int) (chunk []byte, eof bool, err error) {
	buffer := make([]byte, size)

	read, err := io.ReadFull(reader, buffer)

	switch {
	case errors.Is(err, io.EOF):
		// Nothing left to read.
		return nil, true, nil
	case errors.Is(err, io.ErrUnexpectedEOF):
		// Last, partially-filled chunk.
		return buffer[:read], true, nil
	case err != nil:
		return nil, false, err
	default:
		return buffer[:read], false, nil
	}
}
