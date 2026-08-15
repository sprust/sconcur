package serializer

import (
	"fmt"
	"math"
	"strconv"

	"github.com/vmihailenco/msgpack/v5"
	"github.com/vmihailenco/msgpack/v5/msgpcode"
	"go.mongodb.org/mongo-driver/v2/bson"
	"go.mongodb.org/mongo-driver/v2/x/bsonx/bsoncore"
)

// referenceMarker is the value ext-msgpack writes under the nil key instead of a
// class name when the same object instance appears more than once in one payload:
// the repeat is encoded as {nil: 4, 0: <index>} rather than repeating the object.
const referenceMarker = 4

// bsonObject is a decoded object envelope, kept so a later reference to the same
// instance can be re-emitted under its own key.
type bsonObject struct {
	class      string
	properties map[string]interface{}
}

// converter carries the state one payload's decoding needs: the object index that
// makes references resolvable. ext-msgpack numbers every container it writes — a
// map, an array, an object, and a reference itself — from 1, and a reference names
// one of those numbers, so the decoder has to count in exactly the same order.
type converter struct {
	decoder *msgpack.Decoder
	counter int
	objects map[int]*bsonObject
}

func newConverter(decoder *msgpack.Decoder) *converter {
	return &converter{
		decoder: decoder,
		objects: map[int]*bsonObject{},
	}
}

func (c *converter) nextIndex() int {
	c.counter++

	return c.counter
}

// appendValue reads exactly one MessagePack value and appends it to the document
// under key.
func (c *converter) appendValue(document []byte, key string) ([]byte, error) {
	code, err := c.decoder.PeekCode()

	if err != nil {
		return nil, err
	}

	switch {
	case code == msgpcode.Nil:
		if err := c.decoder.DecodeNil(); err != nil {
			return nil, err
		}

		return bsoncore.AppendNullElement(document, key), nil
	case code == msgpcode.True, code == msgpcode.False:
		value, err := c.decoder.DecodeBool()

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendBooleanElement(document, key, value), nil
	case isMsgpackInt(code):
		value, err := c.decoder.DecodeInt64()

		if err != nil {
			return nil, err
		}

		// int32 where it fits, mirroring how the drivers narrow integers; a PHP
		// int that needs the extra width still lands as int64.
		if value >= math.MinInt32 && value <= math.MaxInt32 {
			return bsoncore.AppendInt32Element(document, key, int32(value)), nil
		}

		return bsoncore.AppendInt64Element(document, key, value), nil
	case code == msgpcode.Float, code == msgpcode.Double:
		value, err := c.decoder.DecodeFloat64()

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendDoubleElement(document, key, value), nil
	case msgpcode.IsFixedString(code), code == msgpcode.Str8, code == msgpcode.Str16, code == msgpcode.Str32:
		value, err := c.decoder.DecodeString()

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendStringElement(document, key, value), nil
	case code == msgpcode.Bin8, code == msgpcode.Bin16, code == msgpcode.Bin32:
		value, err := c.decoder.DecodeBytes()

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendBinaryElement(document, key, 0x00, value), nil
	case msgpcode.IsFixedArray(code), code == msgpcode.Array16, code == msgpcode.Array32:
		start, document := bsoncore.AppendArrayElementStart(document, key)

		document, err = c.appendArrayBody(document)

		if err != nil {
			return nil, err
		}

		document, err = bsoncore.AppendArrayEnd(document, start)

		if err != nil {
			return nil, fmt.Errorf("error finishing BSON array: %w", err)
		}

		return document, nil
	case msgpcode.IsFixedMap(code), code == msgpcode.Map16, code == msgpcode.Map32:
		return c.appendMapValue(document, key)
	default:
		return nil, fmt.Errorf("unsupported MessagePack code 0x%02x", code)
	}
}

// appendMapValue turns a map into either a BSON sub-document or, when its first
// key is nil, the BSON type named by the object envelope.
func (c *converter) appendMapValue(document []byte, key string) ([]byte, error) {
	length, err := c.decoder.DecodeMapLen()

	if err != nil {
		return nil, err
	}

	index := c.nextIndex()

	if length > 0 {
		code, err := c.decoder.PeekCode()

		if err != nil {
			return nil, err
		}

		if code == msgpcode.Nil {
			object, err := c.readObject(index, length)

			if err != nil {
				return nil, err
			}

			return appendObject(document, key, object)
		}
	}

	start, document := bsoncore.AppendDocumentElementStart(document, key)

	for pair := 0; pair < length; pair++ {
		name, err := c.decodeMapKey()

		if err != nil {
			return nil, err
		}

		document, err = c.appendValue(document, name)

		if err != nil {
			return nil, fmt.Errorf("key %q: %w", name, err)
		}
	}

	document, err = bsoncore.AppendDocumentEnd(document, start)

	if err != nil {
		return nil, fmt.Errorf("error finishing BSON sub-document: %w", err)
	}

	return document, nil
}

func (c *converter) appendMapBody(document []byte) ([]byte, error) {
	length, err := c.decoder.DecodeMapLen()

	if err != nil {
		return nil, err
	}

	c.nextIndex()

	for pair := 0; pair < length; pair++ {
		key, err := c.decodeMapKey()

		if err != nil {
			return nil, err
		}

		document, err = c.appendValue(document, key)

		if err != nil {
			return nil, fmt.Errorf("key %q: %w", key, err)
		}
	}

	return document, nil
}

func (c *converter) appendArrayBody(document []byte) ([]byte, error) {
	length, err := c.decoder.DecodeArrayLen()

	if err != nil {
		return nil, err
	}

	c.nextIndex()

	for item := 0; item < length; item++ {
		document, err = c.appendValue(document, strconv.Itoa(item))

		if err != nil {
			return nil, fmt.Errorf("index %d: %w", item, err)
		}
	}

	return document, nil
}

// readObject consumes an object envelope — the nil key is next in the stream —
// and returns the object it names, resolving a repeat to the instance recorded
// earlier under the same payload.
func (c *converter) readObject(index int, length int) (*bsonObject, error) {
	if err := c.decoder.DecodeNil(); err != nil {
		return nil, err
	}

	code, err := c.decoder.PeekCode()

	if err != nil {
		return nil, err
	}

	if isMsgpackInt(code) {
		return c.readObjectReference(length)
	}

	class, err := c.decoder.DecodeString()

	if err != nil {
		return nil, fmt.Errorf("error reading object class: %w", err)
	}

	properties := make(map[string]interface{}, length-1)

	for pair := 0; pair < length-1; pair++ {
		name, err := c.decodeMapKey()

		if err != nil {
			return nil, err
		}

		value, err := c.decodeProperty()

		if err != nil {
			return nil, fmt.Errorf("error reading property %q of %s: %w", name, class, err)
		}

		properties[name] = value
	}

	object := &bsonObject{class: class, properties: properties}

	c.objects[index] = object

	return object, nil
}

// readObjectReference resolves {nil: 4, 0: <index>} to the instance already seen.
func (c *converter) readObjectReference(length int) (*bsonObject, error) {
	marker, err := c.decoder.DecodeInt64()

	if err != nil {
		return nil, err
	}

	if marker != referenceMarker {
		return nil, fmt.Errorf("unsupported object marker %d", marker)
	}

	target := int64(-1)

	for pair := 0; pair < length-1; pair++ {
		name, err := c.decodeMapKey()

		if err != nil {
			return nil, err
		}

		value, err := c.decodeProperty()

		if err != nil {
			return nil, err
		}

		if name == "0" {
			if number, ok := value.(int64); ok {
				target = number
			}
		}
	}

	object, ok := c.objects[int(target)]

	if !ok {
		return nil, fmt.Errorf("reference to an unknown object %d", target)
	}

	return object, nil
}

// decodeProperty reads one property value. Nested containers keep the shared
// counter moving, so an object inside a property does not shift later references.
func (c *converter) decodeProperty() (interface{}, error) {
	code, err := c.decoder.PeekCode()

	if err != nil {
		return nil, err
	}

	switch {
	case msgpcode.IsFixedMap(code), code == msgpcode.Map16, code == msgpcode.Map32,
		msgpcode.IsFixedArray(code), code == msgpcode.Array16, code == msgpcode.Array32:
		c.nextIndex()
	}

	return c.decoder.DecodeInterfaceLoose()
}

// decodeMapKey reads a map key. PHP numeric keys arrive as integers, so an integer
// key is accepted and rendered as its decimal string, matching how BSON names
// array elements.
func (c *converter) decodeMapKey() (string, error) {
	code, err := c.decoder.PeekCode()

	if err != nil {
		return "", err
	}

	if isMsgpackInt(code) {
		number, err := c.decoder.DecodeInt64()

		if err != nil {
			return "", err
		}

		return strconv.FormatInt(number, 10), nil
	}

	return c.decoder.DecodeString()
}

// appendObject writes the BSON value an object envelope names.
func appendObject(document []byte, key string, object *bsonObject) ([]byte, error) {
	properties := object.properties

	switch object.class {
	case classObjectId:
		objectId, err := bson.ObjectIDFromHex(propertyString(properties, "oid"))

		if err != nil {
			return nil, fmt.Errorf("invalid ObjectId: %w", err)
		}

		return bsoncore.AppendObjectIDElement(document, key, objectId), nil
	case classUTCDateTime:
		return bsoncore.AppendDateTimeElement(document, key, propertyInt(properties, "epochMs")), nil
	case classBinary:
		subtype := byte(propertyInt(properties, "subType"))

		return bsoncore.AppendBinaryElement(document, key, subtype, propertyBytes(properties, "data")), nil
	case classRegex:
		pattern := propertyString(properties, "pattern")
		flags := propertyString(properties, "flags")

		return bsoncore.AppendRegexElement(document, key, pattern, flags), nil
	case classTimestamp:
		seconds := uint32(propertyInt(properties, "epochSeconds"))
		increment := uint32(propertyInt(properties, "increment"))

		return bsoncore.AppendTimestampElement(document, key, seconds, increment), nil
	case classDecimal128:
		decimal, err := bson.ParseDecimal128(propertyString(properties, "value"))

		if err != nil {
			return nil, fmt.Errorf("invalid Decimal128: %w", err)
		}

		high, low := decimal.GetBytes()

		return bsoncore.AppendDecimal128Element(document, key, high, low), nil
	case classJavascript:
		code := propertyString(properties, "code")
		scope, hasScope := properties["scope"].(map[string]interface{})

		if !hasScope || len(scope) == 0 {
			return bsoncore.AppendJavaScriptElement(document, key, code), nil
		}

		scopeDocument, err := bson.Marshal(scope)

		if err != nil {
			return nil, fmt.Errorf("error encoding Javascript scope: %w", err)
		}

		return bsoncore.AppendCodeWithScopeElement(document, key, code, scopeDocument), nil
	case classMinKey:
		return bsoncore.AppendMinKeyElement(document, key), nil
	case classMaxKey:
		return bsoncore.AppendMaxKeyElement(document, key), nil
	case classInt64:
		return bsoncore.AppendInt64Element(document, key, propertyInt(properties, "value")), nil
	default:
		return nil, fmt.Errorf("unsupported object class %q", object.class)
	}
}

func isMsgpackInt(code byte) bool {
	switch {
	case msgpcode.IsFixedNum(code):
		return true
	case code == msgpcode.Int8, code == msgpcode.Int16, code == msgpcode.Int32, code == msgpcode.Int64:
		return true
	case code == msgpcode.Uint8, code == msgpcode.Uint16, code == msgpcode.Uint32, code == msgpcode.Uint64:
		return true
	default:
		return false
	}
}

func propertyString(properties map[string]interface{}, name string) string {
	switch value := properties[name].(type) {
	case string:
		return value
	case []byte:
		return string(value)
	default:
		return ""
	}
}

func propertyBytes(properties map[string]interface{}, name string) []byte {
	switch value := properties[name].(type) {
	case []byte:
		return value
	case string:
		return []byte(value)
	default:
		return nil
	}
}

func propertyInt(properties map[string]interface{}, name string) int64 {
	switch value := properties[name].(type) {
	case int64:
		return value
	case int32:
		return int64(value)
	case int:
		return int64(value)
	case uint64:
		return int64(value)
	case float64:
		return int64(value)
	default:
		return 0
	}
}
