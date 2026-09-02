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

// classStdClass is PHP's plain object. It names no BSON type — it is simply how an
// object-shaped value reaches a document (json_decode without associative arrays,
// an (object) cast) — so it converts to an ordinary sub-document, the way
// ext-mongodb encoded it before.
const classStdClass = "stdClass"

// decodedPairs is a map decoded whole rather than streamed straight into the
// document. The keys are kept alongside the values because BSON preserves field
// order and a Go map would not.
type decodedPairs struct {
	keys   []string
	values []interface{}
}

func (p *decodedPairs) add(key string, value interface{}) {
	p.keys = append(p.keys, key)
	p.values = append(p.values, value)
}

func (p *decodedPairs) find(key string) (interface{}, bool) {
	for index, name := range p.keys {
		if name == key {
			return p.values[index], true
		}
	}

	return nil, false
}

// decodedList is an array decoded whole, the array counterpart of decodedPairs.
type decodedList struct {
	values []interface{}
}

// bsonObject is a decoded object envelope, kept so a later reference to the same
// instance can be re-emitted under its own key.
type bsonObject struct {
	class      string
	properties *decodedPairs
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

		return appendInt(document, key, value), nil
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

	isObject, err := c.startsWithNilKey(length)

	if err != nil {
		return nil, err
	}

	if isObject {
		object, err := c.readObject(index, length)

		if err != nil {
			return nil, err
		}

		return appendObject(document, key, object)
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

// appendMapBody reads one map and appends its pairs as elements of the
// already-started document. A stdClass envelope is a document too — that is what a
// plain PHP object packs as — and any other envelope is a value, not a document.
func (c *converter) appendMapBody(document []byte) ([]byte, error) {
	length, err := c.decoder.DecodeMapLen()

	if err != nil {
		return nil, err
	}

	index := c.nextIndex()

	isObject, err := c.startsWithNilKey(length)

	if err != nil {
		return nil, err
	}

	if isObject {
		object, err := c.readObject(index, length)

		if err != nil {
			return nil, err
		}

		if object.class != classStdClass {
			return nil, fmt.Errorf("a document cannot be a %s", object.class)
		}

		return appendPairsBody(document, object.properties)
	}

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

// startsWithNilKey reports whether the map whose length was just read is an object
// envelope: ext-msgpack marks one by making the nil key its first.
func (c *converter) startsWithNilKey(length int) (bool, error) {
	if length == 0 {
		return false, nil
	}

	code, err := c.decoder.PeekCode()

	if err != nil {
		return false, err
	}

	return code == msgpcode.Nil, nil
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

	properties := &decodedPairs{
		keys:   make([]string, 0, length-1),
		values: make([]interface{}, 0, length-1),
	}

	for pair := 0; pair < length-1; pair++ {
		name, err := c.decodeMapKey()

		if err != nil {
			return nil, err
		}

		value, err := c.decodeValue()

		if err != nil {
			return nil, fmt.Errorf("error reading property %q of %s: %w", name, class, err)
		}

		properties.add(name, value)
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

		value, err := c.decodeValue()

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

// decodeValue reads one value whole instead of streaming it into the document —
// the shape an object's property needs, since the property is only turned into
// BSON once the class is known.
//
// Containers are walked here rather than handed to the decoder wholesale: every
// container moves the shared counter, and a value object nested in a property (an
// ObjectId inside a Javascript scope) has to be recognised as an object rather
// than decoded into a map with an unusable nil key.
func (c *converter) decodeValue() (interface{}, error) {
	code, err := c.decoder.PeekCode()

	if err != nil {
		return nil, err
	}

	switch {
	case msgpcode.IsFixedMap(code), code == msgpcode.Map16, code == msgpcode.Map32:
		return c.decodeMap()
	case msgpcode.IsFixedArray(code), code == msgpcode.Array16, code == msgpcode.Array32:
		return c.decodeList()
	default:
		return c.decoder.DecodeInterfaceLoose()
	}
}

func (c *converter) decodeMap() (interface{}, error) {
	length, err := c.decoder.DecodeMapLen()

	if err != nil {
		return nil, err
	}

	index := c.nextIndex()

	isObject, err := c.startsWithNilKey(length)

	if err != nil {
		return nil, err
	}

	if isObject {
		return c.readObject(index, length)
	}

	pairs := &decodedPairs{
		keys:   make([]string, 0, length),
		values: make([]interface{}, 0, length),
	}

	for pair := 0; pair < length; pair++ {
		key, err := c.decodeMapKey()

		if err != nil {
			return nil, err
		}

		value, err := c.decodeValue()

		if err != nil {
			return nil, fmt.Errorf("key %q: %w", key, err)
		}

		pairs.add(key, value)
	}

	return pairs, nil
}

func (c *converter) decodeList() (interface{}, error) {
	length, err := c.decoder.DecodeArrayLen()

	if err != nil {
		return nil, err
	}

	c.nextIndex()

	list := &decodedList{values: make([]interface{}, 0, length)}

	for item := 0; item < length; item++ {
		value, err := c.decodeValue()

		if err != nil {
			return nil, fmt.Errorf("index %d: %w", item, err)
		}

		list.values = append(list.values, value)
	}

	return list, nil
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
	switch object.class {
	case classStdClass:
		return appendPairsElement(document, key, object.properties)
	case classObjectId:
		hexadecimal, err := propertyString(object, "oid")

		if err != nil {
			return nil, err
		}

		objectId, err := bson.ObjectIDFromHex(hexadecimal)

		if err != nil {
			return nil, fmt.Errorf("invalid ObjectId: %w", err)
		}

		return bsoncore.AppendObjectIDElement(document, key, objectId), nil
	case classUTCDateTime:
		epochMs, err := propertyInt(object, "epochMs")

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendDateTimeElement(document, key, epochMs), nil
	case classBinary:
		subtype, err := propertyRange(object, "subType", math.MaxUint8)

		if err != nil {
			return nil, err
		}

		data, err := propertyBytes(object, "data")

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendBinaryElement(document, key, byte(subtype), data), nil
	case classRegex:
		pattern, err := propertyString(object, "pattern")

		if err != nil {
			return nil, err
		}

		flags, err := propertyString(object, "flags")

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendRegexElement(document, key, pattern, flags), nil
	case classTimestamp:
		seconds, err := propertyRange(object, "epochSeconds", math.MaxUint32)

		if err != nil {
			return nil, err
		}

		increment, err := propertyRange(object, "increment", math.MaxUint32)

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendTimestampElement(document, key, uint32(seconds), uint32(increment)), nil
	case classDecimal128:
		value, err := propertyString(object, "value")

		if err != nil {
			return nil, err
		}

		decimal, err := bson.ParseDecimal128(value)

		if err != nil {
			return nil, fmt.Errorf("invalid Decimal128: %w", err)
		}

		high, low := decimal.GetBytes()

		return bsoncore.AppendDecimal128Element(document, key, high, low), nil
	case classJavascript:
		return appendJavascript(document, key, object)
	case classMinKey:
		return bsoncore.AppendMinKeyElement(document, key), nil
	case classMaxKey:
		return bsoncore.AppendMaxKeyElement(document, key), nil
	case classInt64:
		value, err := propertyInt(object, "value")

		if err != nil {
			return nil, err
		}

		return bsoncore.AppendInt64Element(document, key, value), nil
	default:
		return nil, fmt.Errorf("unsupported object class %q", object.class)
	}
}

// appendJavascript writes a plain javascript element, or a code-with-scope one
// when the object carries a non-empty scope.
func appendJavascript(document []byte, key string, object *bsonObject) ([]byte, error) {
	code, err := propertyString(object, "code")

	if err != nil {
		return nil, err
	}

	scope, err := propertyPairs(object, "scope")

	if err != nil {
		return nil, err
	}

	if scope == nil || len(scope.keys) == 0 {
		return bsoncore.AppendJavaScriptElement(document, key, code), nil
	}

	scopeDocument, err := documentFromPairs(scope)

	if err != nil {
		return nil, fmt.Errorf("error encoding Javascript scope: %w", err)
	}

	return bsoncore.AppendCodeWithScopeElement(document, key, code, scopeDocument), nil
}

// appendDecodedValue appends a value that was read whole — an object property, or
// something nested inside one — as a BSON element.
func appendDecodedValue(document []byte, key string, value interface{}) ([]byte, error) {
	switch typed := value.(type) {
	case nil:
		return bsoncore.AppendNullElement(document, key), nil
	case bool:
		return bsoncore.AppendBooleanElement(document, key, typed), nil
	case string:
		return bsoncore.AppendStringElement(document, key, typed), nil
	case []byte:
		return bsoncore.AppendBinaryElement(document, key, 0x00, typed), nil
	case float32:
		return bsoncore.AppendDoubleElement(document, key, float64(typed)), nil
	case float64:
		return bsoncore.AppendDoubleElement(document, key, typed), nil
	case int8, int16, int32, int, int64, uint8, uint16, uint32, uint, uint64:
		number, err := toInt64(typed)

		if err != nil {
			return nil, err
		}

		return appendInt(document, key, number), nil
	case *decodedPairs:
		return appendPairsElement(document, key, typed)
	case *decodedList:
		return appendListElement(document, key, typed)
	case *bsonObject:
		return appendObject(document, key, typed)
	default:
		return nil, fmt.Errorf("unsupported decoded value of type %T", value)
	}
}

func appendPairsElement(document []byte, key string, pairs *decodedPairs) ([]byte, error) {
	start, document := bsoncore.AppendDocumentElementStart(document, key)

	document, err := appendPairsBody(document, pairs)

	if err != nil {
		return nil, err
	}

	document, err = bsoncore.AppendDocumentEnd(document, start)

	if err != nil {
		return nil, fmt.Errorf("error finishing BSON sub-document: %w", err)
	}

	return document, nil
}

func appendPairsBody(document []byte, pairs *decodedPairs) ([]byte, error) {
	var err error

	for index, key := range pairs.keys {
		document, err = appendDecodedValue(document, key, pairs.values[index])

		if err != nil {
			return nil, fmt.Errorf("key %q: %w", key, err)
		}
	}

	return document, nil
}

func appendListElement(document []byte, key string, list *decodedList) ([]byte, error) {
	start, document := bsoncore.AppendArrayElementStart(document, key)

	var err error

	for index, value := range list.values {
		document, err = appendDecodedValue(document, strconv.Itoa(index), value)

		if err != nil {
			return nil, fmt.Errorf("index %d: %w", index, err)
		}
	}

	document, err = bsoncore.AppendArrayEnd(document, start)

	if err != nil {
		return nil, fmt.Errorf("error finishing BSON array: %w", err)
	}

	return document, nil
}

// documentFromPairs turns decoded pairs into a standalone BSON document.
func documentFromPairs(pairs *decodedPairs) ([]byte, error) {
	index, document := bsoncore.AppendDocumentStart(nil)

	document, err := appendPairsBody(document, pairs)

	if err != nil {
		return nil, err
	}

	document, err = bsoncore.AppendDocumentEnd(document, index)

	if err != nil {
		return nil, fmt.Errorf("error finishing BSON document: %w", err)
	}

	return document, nil
}

// appendInt narrows to int32 where the value fits, mirroring how the drivers write
// integers; a PHP int that needs the extra width still lands as int64.
func appendInt(document []byte, key string, value int64) []byte {
	if value >= math.MinInt32 && value <= math.MaxInt32 {
		return bsoncore.AppendInt32Element(document, key, int32(value))
	}

	return bsoncore.AppendInt64Element(document, key, value)
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

// propertyValue reads a property of a decoded object. A missing one is an error
// rather than a zero value: the payload is then not what the class promises, and a
// silent zero would reach the collection as a real date or subtype.
func propertyValue(object *bsonObject, name string) (interface{}, error) {
	value, ok := object.properties.find(name)

	if !ok {
		return nil, fmt.Errorf("%s carries no property %q", object.class, name)
	}

	return value, nil
}

func propertyString(object *bsonObject, name string) (string, error) {
	value, err := propertyValue(object, name)

	if err != nil {
		return "", err
	}

	switch typed := value.(type) {
	case string:
		return typed, nil
	case []byte:
		return string(typed), nil
	default:
		return "", fmt.Errorf("property %q of %s is %T, expected a string", name, object.class, value)
	}
}

func propertyBytes(object *bsonObject, name string) ([]byte, error) {
	value, err := propertyValue(object, name)

	if err != nil {
		return nil, err
	}

	switch typed := value.(type) {
	case []byte:
		return typed, nil
	case string:
		return []byte(typed), nil
	default:
		return nil, fmt.Errorf("property %q of %s is %T, expected a string", name, object.class, value)
	}
}

func propertyInt(object *bsonObject, name string) (int64, error) {
	value, err := propertyValue(object, name)

	if err != nil {
		return 0, err
	}

	number, err := toInt64(value)

	if err != nil {
		return 0, fmt.Errorf("property %q of %s: %w", name, object.class, err)
	}

	return number, nil
}

// propertyRange reads an integer property that BSON stores in a narrower field
// than a PHP int, so an out-of-range value is rejected instead of wrapping.
func propertyRange(object *bsonObject, name string, maximum int64) (int64, error) {
	number, err := propertyInt(object, name)

	if err != nil {
		return 0, err
	}

	if number < 0 || number > maximum {
		return 0, fmt.Errorf("property %q of %s is %d, expected 0..%d", name, object.class, number, maximum)
	}

	return number, nil
}

// propertyPairs reads a property that holds a nested document. A null one (a
// Javascript without a scope) is absence, not an error.
func propertyPairs(object *bsonObject, name string) (*decodedPairs, error) {
	value, err := propertyValue(object, name)

	if err != nil {
		return nil, err
	}

	switch typed := value.(type) {
	case nil:
		return nil, nil
	case *decodedPairs:
		return typed, nil
	case *decodedList:
		// An empty PHP array packs as a list, and so does one with sequential keys.
		pairs := &decodedPairs{
			keys:   make([]string, 0, len(typed.values)),
			values: make([]interface{}, 0, len(typed.values)),
		}

		for index, item := range typed.values {
			pairs.add(strconv.Itoa(index), item)
		}

		return pairs, nil
	default:
		return nil, fmt.Errorf("property %q of %s is %T, expected a document", name, object.class, value)
	}
}

func toInt64(value interface{}) (int64, error) {
	switch typed := value.(type) {
	case int64:
		return typed, nil
	case int32:
		return int64(typed), nil
	case int16:
		return int64(typed), nil
	case int8:
		return int64(typed), nil
	case int:
		return int64(typed), nil
	case uint64:
		if typed > math.MaxInt64 {
			return 0, fmt.Errorf("%d does not fit in a signed 64-bit integer", typed)
		}

		return int64(typed), nil
	case uint32:
		return int64(typed), nil
	case uint16:
		return int64(typed), nil
	case uint8:
		return int64(typed), nil
	case uint:
		if uint64(typed) > math.MaxInt64 {
			return 0, fmt.Errorf("%d does not fit in a signed 64-bit integer", typed)
		}

		return int64(typed), nil
	default:
		return 0, fmt.Errorf("value is %T, expected an integer", value)
	}
}
