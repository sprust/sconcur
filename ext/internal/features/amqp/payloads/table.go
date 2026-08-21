package payloads

import (
	"fmt"
	"strconv"

	"github.com/vmihailenco/msgpack/v5"
	"github.com/vmihailenco/msgpack/v5/msgpcode"
)

// Table is an AMQP field table — the queue and exchange arguments, and the message
// headers — as it arrives from PHP.
//
// It decodes itself because a PHP array is neither a map nor a list and its keys are not
// necessarily strings:
//
//   - an empty PHP array packs as an empty MessagePack array, not as an empty map (the
//     same asymmetry the MongoDB serializer handles, see docs/msgpack-objects.md);
//   - a numeric key stays numeric however PHP is asked to write it, so a nested table like
//     `[0 => 'zero', 'k' => 'value']` arrives with one integer key and one string key. AMQP
//     field names are strings, and so is what the extension sends, so the integer keys are
//     stringified here.
//
// A nested array with no string keys is left as a list: that is an AMQP field array.
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
		values, err := decodeTable(decoder)

		if err != nil {
			return err
		}

		*t = values

		return nil
	}
}

// decodeTable reads one map, stringifying whatever the keys turned out to be.
func decodeTable(decoder *msgpack.Decoder) (map[string]any, error) {
	length, err := decoder.DecodeMapLen()

	if err != nil {
		return nil, err
	}

	if length <= 0 {
		return nil, nil
	}

	table := make(map[string]any, length)

	for index := 0; index < length; index++ {
		key, err := decoder.DecodeInterfaceLoose()

		if err != nil {
			return nil, err
		}

		value, err := decodeTableValue(decoder)

		if err != nil {
			return nil, err
		}

		table[tableKey(key)] = value
	}

	return table, nil
}

// decodeTableValue reads one field value, keeping a nested map a map and a nested list a
// list — the distinction between an AMQP field table and an AMQP field array.
func decodeTableValue(decoder *msgpack.Decoder) (any, error) {
	code, err := decoder.PeekCode()

	if err != nil {
		return nil, err
	}

	switch {
	case msgpcode.IsFixedMap(code), code == msgpcode.Map16, code == msgpcode.Map32:
		return decodeTable(decoder)
	case msgpcode.IsFixedArray(code), code == msgpcode.Array16, code == msgpcode.Array32:
		length, err := decoder.DecodeArrayLen()

		if err != nil {
			return nil, err
		}

		values := make([]any, 0, max(length, 0))

		for index := 0; index < length; index++ {
			value, err := decodeTableValue(decoder)

			if err != nil {
				return nil, err
			}

			values = append(values, value)
		}

		return values, nil
	default:
		return decoder.DecodeInterfaceLoose()
	}
}

func tableKey(key any) string {
	switch typed := key.(type) {
	case string:
		return typed
	case int64:
		return strconv.FormatInt(typed, 10)
	case uint64:
		return strconv.FormatUint(typed, 10)
	case float64:
		return strconv.FormatFloat(typed, 'f', -1, 64)
	default:
		return fmt.Sprint(typed)
	}
}
