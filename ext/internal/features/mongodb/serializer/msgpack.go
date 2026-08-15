package serializer

import (
	"bytes"
	"fmt"
	"sync"

	"github.com/vmihailenco/msgpack/v5"
	"github.com/vmihailenco/msgpack/v5/msgpcode"
	"go.mongodb.org/mongo-driver/v2/bson"
	"go.mongodb.org/mongo-driver/v2/x/bsonx/bsoncore"
)

// Documents cross the boundary as MessagePack, so the PHP side needs no BSON
// codec of its own and SConcur does not depend on ext-mongodb.
//
// BSON has types MessagePack does not (an id, a date, a decimal, ...). They ride
// in the object envelope ext-msgpack uses for PHP objects: a map whose FIRST key
// is nil and whose value is the class name, followed by property/value pairs.
// The PHP C unpacker materialises the object at that point of the parse, so the
// PHP side never walks the structure — which is what made the previous
// hand-rolled converter expensive.
//
// The nil key is unambiguous: BSON keys are always strings, and a PHP array
// cannot hold a null key (it coerces to ""). Requires msgpack.php_only=1 on the
// PHP side, which SConcur sets explicitly.
// bsonHeadroom pads the BSON destination: BSON carries a type byte and a
// NUL-terminated key per element, so it runs a little longer than the MessagePack
// it came from.
const bsonHeadroom = 512

const (
	classObjectId    = `SConcur\Bson\ObjectId`
	classUTCDateTime = `SConcur\Bson\UTCDateTime`
	classBinary      = `SConcur\Bson\Binary`
	classRegex       = `SConcur\Bson\Regex`
	classTimestamp   = `SConcur\Bson\Timestamp`
	classDecimal128  = `SConcur\Bson\Decimal128`
	classJavascript  = `SConcur\Bson\Javascript`
	classMinKey      = `SConcur\Bson\MinKey`
	classMaxKey      = `SConcur\Bson\MaxKey`
	classInt64       = `SConcur\Bson\Int64`
)

// Encoders and decoders are pooled and the buffers pre-sized from the payload:
// this conversion runs on every document in both directions, and on the old raw
// BSON path it cost nothing at all, so its allocations are worth keeping down.
var (
	encoderPool = sync.Pool{New: func() interface{} { return msgpack.NewEncoder(nil) }}
	decoderPool = sync.Pool{New: func() interface{} { return msgpack.NewDecoder(nil) }}
)

func acquireEncoder(buffer *bytes.Buffer) *msgpack.Encoder {
	encoder := encoderPool.Get().(*msgpack.Encoder)
	encoder.Reset(buffer)

	return encoder
}

func acquireDecoder(reader *bytes.Reader) *msgpack.Decoder {
	decoder := decoderPool.Get().(*msgpack.Decoder)
	decoder.Reset(reader)

	return decoder
}

// BSONToMsgpack converts a raw BSON document to MessagePack in a single pass over
// the document's elements: no intermediate map is built, because materialising
// one would cost more than the conversion itself.
func BSONToMsgpack(raw bson.Raw) ([]byte, error) {
	var buffer bytes.Buffer

	// MessagePack is consistently smaller than the BSON it came from, so the
	// source length is a safe upper bound and the buffer never regrows.
	buffer.Grow(len(raw))

	encoder := acquireEncoder(&buffer)
	defer encoderPool.Put(encoder)

	if err := encodeBSONDocument(encoder, raw); err != nil {
		return nil, err
	}

	return buffer.Bytes(), nil
}

// BSONBatchToMsgpack converts a batch of cursor documents to a MessagePack list.
// The BSON path wrapped batches in a {"d": [...]} document because PHP could only
// decode a document; a MessagePack array needs no wrapper.
func BSONBatchToMsgpack(items []bson.Raw) ([]byte, error) {
	var buffer bytes.Buffer

	size := 0

	for _, item := range items {
		size += len(item)
	}

	buffer.Grow(size)

	encoder := acquireEncoder(&buffer)
	defer encoderPool.Put(encoder)

	if err := encoder.EncodeArrayLen(len(items)); err != nil {
		return nil, err
	}

	for index, item := range items {
		if err := encodeBSONDocument(encoder, item); err != nil {
			return nil, fmt.Errorf("error encoding batch document %d: %w", index, err)
		}
	}

	return buffer.Bytes(), nil
}

func encodeBSONDocument(encoder *msgpack.Encoder, raw bson.Raw) error {
	elements, err := raw.Elements()

	if err != nil {
		return fmt.Errorf("error reading BSON document: %w", err)
	}

	if err := encoder.EncodeMapLen(len(elements)); err != nil {
		return err
	}

	for _, element := range elements {
		if err := encoder.EncodeString(element.Key()); err != nil {
			return err
		}

		if err := encodeBSONValue(encoder, element.Value()); err != nil {
			return fmt.Errorf("error encoding key %q: %w", element.Key(), err)
		}
	}

	return nil
}

func encodeBSONArray(encoder *msgpack.Encoder, raw bson.Raw) error {
	values, err := raw.Values()

	if err != nil {
		return fmt.Errorf("error reading BSON array: %w", err)
	}

	if err := encoder.EncodeArrayLen(len(values)); err != nil {
		return err
	}

	for index, value := range values {
		if err := encodeBSONValue(encoder, value); err != nil {
			return fmt.Errorf("error encoding index %d: %w", index, err)
		}
	}

	return nil
}

func encodeBSONValue(encoder *msgpack.Encoder, value bson.RawValue) error {
	switch value.Type {
	case bson.TypeDouble:
		return encoder.EncodeFloat64(value.Double())
	case bson.TypeString:
		return encoder.EncodeString(value.StringValue())
	case bson.TypeEmbeddedDocument:
		return encodeBSONDocument(encoder, value.Document())
	case bson.TypeArray:
		return encodeBSONArray(encoder, bson.Raw(value.Array()))
	case bson.TypeBoolean:
		return encoder.EncodeBool(value.Boolean())
	case bson.TypeInt32:
		return encoder.EncodeInt(int64(value.Int32()))
	case bson.TypeInt64:
		// Wrapped, exactly as the native driver hands an int64 to PHP: the type
		// must survive a read-modify-write, and a plain int would come back as an
		// int32 whenever the value happens to fit.
		if err := encodeObjectHeader(encoder, classInt64, 1); err != nil {
			return err
		}

		if err := encoder.EncodeString("value"); err != nil {
			return err
		}

		return encoder.EncodeInt(value.Int64())
	case bson.TypeNull, bson.TypeUndefined:
		return encoder.EncodeNil()
	case bson.TypeObjectID:
		if err := encodeObjectHeader(encoder, classObjectId, 1); err != nil {
			return err
		}

		if err := encoder.EncodeString("oid"); err != nil {
			return err
		}

		return encoder.EncodeString(value.ObjectID().Hex())
	case bson.TypeDateTime:
		if err := encodeObjectHeader(encoder, classUTCDateTime, 1); err != nil {
			return err
		}

		if err := encoder.EncodeString("epochMs"); err != nil {
			return err
		}

		return encoder.EncodeInt(value.DateTime())
	case bson.TypeBinary:
		subtype, data := value.Binary()

		if err := encodeObjectHeader(encoder, classBinary, 2); err != nil {
			return err
		}

		if err := encoder.EncodeString("data"); err != nil {
			return err
		}

		if err := encoder.EncodeBytes(data); err != nil {
			return err
		}

		if err := encoder.EncodeString("subType"); err != nil {
			return err
		}

		return encoder.EncodeInt(int64(subtype))
	case bson.TypeRegex:
		pattern, flags := value.Regex()

		if err := encodeObjectHeader(encoder, classRegex, 2); err != nil {
			return err
		}

		if err := encoder.EncodeString("pattern"); err != nil {
			return err
		}

		if err := encoder.EncodeString(pattern); err != nil {
			return err
		}

		if err := encoder.EncodeString("flags"); err != nil {
			return err
		}

		return encoder.EncodeString(flags)
	case bson.TypeTimestamp:
		seconds, increment := value.Timestamp()

		if err := encodeObjectHeader(encoder, classTimestamp, 2); err != nil {
			return err
		}

		if err := encoder.EncodeString("increment"); err != nil {
			return err
		}

		if err := encoder.EncodeInt(int64(increment)); err != nil {
			return err
		}

		if err := encoder.EncodeString("epochSeconds"); err != nil {
			return err
		}

		return encoder.EncodeInt(int64(seconds))
	case bson.TypeDecimal128:
		if err := encodeObjectHeader(encoder, classDecimal128, 1); err != nil {
			return err
		}

		if err := encoder.EncodeString("value"); err != nil {
			return err
		}

		return encoder.EncodeString(value.Decimal128().String())
	case bson.TypeJavaScript:
		if err := encodeObjectHeader(encoder, classJavascript, 2); err != nil {
			return err
		}

		if err := encoder.EncodeString("code"); err != nil {
			return err
		}

		if err := encoder.EncodeString(value.JavaScript()); err != nil {
			return err
		}

		if err := encoder.EncodeString("scope"); err != nil {
			return err
		}

		return encoder.EncodeNil()
	case bson.TypeCodeWithScope:
		code, scope := value.CodeWithScope()

		if err := encodeObjectHeader(encoder, classJavascript, 2); err != nil {
			return err
		}

		if err := encoder.EncodeString("code"); err != nil {
			return err
		}

		if err := encoder.EncodeString(code); err != nil {
			return err
		}

		if err := encoder.EncodeString("scope"); err != nil {
			return err
		}

		return encodeBSONDocument(encoder, scope)
	case bson.TypeMinKey:
		return encodeObjectHeader(encoder, classMinKey, 0)
	case bson.TypeMaxKey:
		return encodeObjectHeader(encoder, classMaxKey, 0)
	case bson.TypeSymbol:
		// Deprecated in BSON; the server returns it only for legacy data.
		symbol, _ := value.SymbolOK()

		return encoder.EncodeString(symbol)
	default:
		return fmt.Errorf("unsupported BSON type %s", value.Type.String())
	}
}

// encodeObjectHeader writes the envelope ext-msgpack reads as a PHP object: a map
// of propertyCount+1 pairs whose first key is nil and whose value is the class.
func encodeObjectHeader(encoder *msgpack.Encoder, class string, propertyCount int) error {
	if err := encoder.EncodeMapLen(propertyCount + 1); err != nil {
		return err
	}

	if err := encoder.EncodeNil(); err != nil {
		return err
	}

	return encoder.EncodeString(class)
}

// MsgpackToBSON converts a MessagePack document from PHP into raw BSON the driver
// accepts directly. A MessagePack array becomes a BSON array document (keys "0",
// "1", ...), which is what an empty filter or a pipeline arrives as.
func MsgpackToBSON(data []byte) (bson.Raw, error) {
	if len(data) == 0 {
		return bson.Raw{}, nil
	}

	reader := bytes.NewReader(data)
	decoder := acquireDecoder(reader)

	defer decoderPool.Put(decoder)

	instance := newConverter(decoder)

	code, err := decoder.PeekCode()

	if err != nil {
		return nil, fmt.Errorf("error reading MessagePack payload: %w", err)
	}

	var (
		index    int32
		document []byte
	)

	switch {
	case msgpcode.IsFixedMap(code), code == msgpcode.Map16, code == msgpcode.Map32:
		index, document = bsoncore.AppendDocumentStart(make([]byte, 0, len(data)+bsonHeadroom))

		document, err = instance.appendMapBody(document)
	case msgpcode.IsFixedArray(code), code == msgpcode.Array16, code == msgpcode.Array32:
		index, document = bsoncore.AppendDocumentStart(make([]byte, 0, len(data)+bsonHeadroom))

		document, err = instance.appendArrayBody(document)
	case code == msgpcode.Nil:
		return bson.Raw{}, nil
	default:
		return nil, fmt.Errorf("MessagePack payload is not a document (code 0x%02x)", code)
	}

	if err != nil {
		return nil, err
	}

	document, err = bsoncore.AppendDocumentEnd(document, index)

	if err != nil {
		return nil, fmt.Errorf("error finishing BSON document: %w", err)
	}

	return bson.Raw(document), nil
}

// MsgpackToBSONDocuments converts a MessagePack list of documents (insertMany, a
// pipeline) into driver-ready documents. The object index is shared across the
// whole list, since PHP numbered it that way when it packed the payload.
func MsgpackToBSONDocuments(data []byte) ([]interface{}, error) {
	if len(data) == 0 {
		return []interface{}{}, nil
	}

	reader := bytes.NewReader(data)
	decoder := acquireDecoder(reader)

	defer decoderPool.Put(decoder)

	instance := newConverter(decoder)

	length, err := decoder.DecodeArrayLen()

	if err != nil {
		return nil, fmt.Errorf("MessagePack payload is not a list: %w", err)
	}

	instance.nextIndex()

	documents := make([]interface{}, 0, length)

	for item := 0; item < length; item++ {
		index, document := bsoncore.AppendDocumentStart(nil)

		document, err = instance.appendContainerBody(document)

		if err != nil {
			return nil, fmt.Errorf("error reading element %d: %w", item, err)
		}

		document, err = bsoncore.AppendDocumentEnd(document, index)

		if err != nil {
			return nil, fmt.Errorf("error finishing element %d: %w", item, err)
		}

		documents = append(documents, bson.Raw(document))
	}

	return documents, nil
}

// appendContainerBody reads one map or array and appends its pairs as BSON
// elements of the already-started document.
func (c *converter) appendContainerBody(document []byte) ([]byte, error) {
	code, err := c.decoder.PeekCode()

	if err != nil {
		return nil, err
	}

	switch {
	case msgpcode.IsFixedMap(code), code == msgpcode.Map16, code == msgpcode.Map32:
		return c.appendMapBody(document)
	case msgpcode.IsFixedArray(code), code == msgpcode.Array16, code == msgpcode.Array32:
		return c.appendArrayBody(document)
	default:
		return nil, fmt.Errorf("expected a document (code 0x%02x)", code)
	}
}
