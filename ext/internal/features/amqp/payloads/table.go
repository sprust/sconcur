package payloads

import (
	"fmt"

	"github.com/vmihailenco/msgpack/v5"
	"github.com/vmihailenco/msgpack/v5/msgpcode"
)

// Table is an AMQP field table — the queue and exchange arguments, and the message
// headers — as it arrives from PHP.
//
// It decodes itself because PHP has one array type for both shapes: an empty PHP array
// packs as an empty MessagePack array, not as an empty map, so a plain map field would
// fail to decode the common "no arguments" case (the same asymmetry the MongoDB
// serializer handles, see docs/msgpack-objects.md).
type Table map[string]any

func (t *Table) DecodeMsgpack(decoder *msgpack.Decoder) error {
	code, err := decoder.PeekCode()

	if err != nil {
		return err
	}

	switch {
	case code == msgpcode.Nil:
		return decoder.DecodeNil()
	case msgpcode.IsFixedArray(code), code == msgpcode.Array16, code == msgpcode.Array32:
		length, err := decoder.DecodeArrayLen()

		if err != nil {
			return err
		}

		if length > 0 {
			return fmt.Errorf("a field table must be a map, got a MessagePack array of %d values", length)
		}

		return nil
	default:
		values, err := decoder.DecodeMap()

		if err != nil {
			return err
		}

		*t = values

		return nil
	}
}
