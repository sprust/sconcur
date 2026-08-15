package serializer

import (
	"bytes"
	"encoding/hex"
	"testing"

	"github.com/vmihailenco/msgpack/v5"
	"go.mongodb.org/mongo-driver/v2/bson"
)

// buildDocument marshals a bson.D and fails the test on error.
func buildDocument(t *testing.T, document bson.D) bson.Raw {
	t.Helper()

	raw, err := bson.Marshal(document)

	if err != nil {
		t.Fatalf("marshaling BSON: %v", err)
	}

	return raw
}

// roundTrip runs a BSON document through MessagePack and back, which is the exact
// path a value takes between the driver and PHP user code.
func roundTrip(t *testing.T, document bson.Raw) bson.Raw {
	t.Helper()

	encoded, err := BSONToMsgpack(document)

	if err != nil {
		t.Fatalf("BSON -> MessagePack: %v", err)
	}

	decoded, err := MsgpackToBSON(encoded)

	if err != nil {
		t.Fatalf("MessagePack -> BSON: %v", err)
	}

	return decoded
}

func TestRoundTripsEveryBSONType(t *testing.T) {
	objectId, err := bson.ObjectIDFromHex("6919e3d1a3673d3f4d9137a3")

	if err != nil {
		t.Fatalf("parsing ObjectId: %v", err)
	}

	decimal, err := bson.ParseDecimal128("3.14159")

	if err != nil {
		t.Fatalf("parsing Decimal128: %v", err)
	}

	source := buildDocument(t, bson.D{
		{Key: "int32", Value: int32(123)},
		{Key: "int64", Value: int64(9000000000)},
		{Key: "double", Value: 123.456},
		{Key: "string", Value: "hello"},
		{Key: "bool", Value: true},
		{Key: "null", Value: nil},
		{Key: "objectId", Value: objectId},
		{Key: "date", Value: bson.DateTime(1700000000000)},
		{Key: "binary", Value: bson.Binary{Subtype: 0x00, Data: []byte("binary-data")}},
		{Key: "regex", Value: bson.Regex{Pattern: "^abc", Options: "i"}},
		{Key: "timestamp", Value: bson.Timestamp{T: 1700000000, I: 1}},
		{Key: "decimal128", Value: decimal},
		{Key: "minKey", Value: bson.MinKey{}},
		{Key: "maxKey", Value: bson.MaxKey{}},
		{Key: "javascript", Value: bson.JavaScript("function () { return 1; }")},
		{Key: "document", Value: bson.D{{Key: "nested", Value: "value"}, {Key: "number", Value: int32(7)}}},
		{Key: "array", Value: bson.A{int32(1), int32(2), int32(3)}},
		{Key: "objectIds", Value: bson.A{objectId}},
	})

	result := roundTrip(t, source)

	if !bytes.Equal(source, result) {
		t.Errorf("document changed across the round trip\n source: %s\n result: %s", source.String(), result.String())
	}
}

func TestRoundTripsNestedSpecialValues(t *testing.T) {
	objectId, err := bson.ObjectIDFromHex("6919e3d1a3673d3f4d9137a3")

	if err != nil {
		t.Fatalf("parsing ObjectId: %v", err)
	}

	source := buildDocument(t, bson.D{
		{Key: "deep", Value: bson.D{
			{Key: "deeper", Value: bson.D{
				{Key: "id", Value: objectId},
				{Key: "at", Value: bson.DateTime(1)},
			}},
		}},
		{Key: "list", Value: bson.A{objectId, bson.DateTime(2), bson.A{objectId}}},
	})

	result := roundTrip(t, source)

	if !bytes.Equal(source, result) {
		t.Errorf("nested document changed\n source: %s\n result: %s", source.String(), result.String())
	}
}

func TestRoundTripsEmptyAndEdgeDocuments(t *testing.T) {
	cases := map[string]bson.D{
		"empty":        {},
		"empty nested": {{Key: "sub", Value: bson.D{}}},
		"empty array":  {{Key: "list", Value: bson.A{}}},
		"empty string": {{Key: "text", Value: ""}},
		"negative int": {{Key: "value", Value: int32(-42)}},
		"int32 bounds": {{Key: "min", Value: int32(-2147483648)}, {Key: "max", Value: int32(2147483647)}},
		"int64 bounds": {{Key: "min", Value: int64(-9223372036854775808)}, {Key: "max", Value: int64(9223372036854775807)}},
		"negative date": {{Key: "before epoch", Value: bson.DateTime(-1000)}},
		"binary bytes": {{Key: "raw", Value: bson.Binary{Subtype: 0x00, Data: []byte{0x00, 0xff, 0x10}}}},
		"multibyte keys": {{Key: "clé-Ω-\U0001F600", Value: "naïve-Ω-\U0001F600"}},
	}

	for name, document := range cases {
		t.Run(name, func(t *testing.T) {
			source := buildDocument(t, document)
			result := roundTrip(t, source)

			if !bytes.Equal(source, result) {
				t.Errorf("changed across the round trip\n source: %s\n result: %s", source.String(), result.String())
			}
		})
	}
}

// The PHP side never walks the structure: the extension materialises objects
// while parsing, and it only does that for a map whose FIRST key is nil. Pin the
// layout, because a reordering would silently degrade into a plain array on the
// PHP side rather than fail.
func TestObjectEnvelopeLayout(t *testing.T) {
	objectId, err := bson.ObjectIDFromHex("6919e3d1a3673d3f4d9137a3")

	if err != nil {
		t.Fatalf("parsing ObjectId: %v", err)
	}

	encoded, err := BSONToMsgpack(buildDocument(t, bson.D{{Key: "id", Value: objectId}}))

	if err != nil {
		t.Fatalf("encoding: %v", err)
	}

	decoder := msgpack.NewDecoder(bytes.NewReader(encoded))

	if _, err := decoder.DecodeMapLen(); err != nil {
		t.Fatalf("reading document map: %v", err)
	}

	if key, err := decoder.DecodeString(); err != nil || key != "id" {
		t.Fatalf("expected key %q, got %q (%v)", "id", key, err)
	}

	length, err := decoder.DecodeMapLen()

	if err != nil {
		t.Fatalf("reading object map: %v", err)
	}

	if length != 2 {
		t.Errorf("expected 2 pairs in the envelope (class plus one property), got %d", length)
	}

	code, err := decoder.PeekCode()

	if err != nil {
		t.Fatalf("peeking the first key: %v", err)
	}

	if code != 0xc0 {
		t.Fatalf("the first key of the envelope must be nil (0xc0), got 0x%02x", code)
	}

	if err := decoder.DecodeNil(); err != nil {
		t.Fatalf("consuming the nil key: %v", err)
	}

	class, err := decoder.DecodeString()

	if err != nil {
		t.Fatalf("reading the class name: %v", err)
	}

	if class != classObjectId {
		t.Errorf("expected class %q, got %q", classObjectId, class)
	}
}

func TestBatchEncodesAsList(t *testing.T) {
	items := []bson.Raw{
		buildDocument(t, bson.D{{Key: "n", Value: int32(1)}}),
		buildDocument(t, bson.D{{Key: "n", Value: int32(2)}}),
	}

	encoded, err := BSONBatchToMsgpack(items)

	if err != nil {
		t.Fatalf("encoding batch: %v", err)
	}

	decoder := msgpack.NewDecoder(bytes.NewReader(encoded))

	length, err := decoder.DecodeArrayLen()

	if err != nil {
		t.Fatalf("batch is not a MessagePack list: %v", err)
	}

	if length != len(items) {
		t.Errorf("expected %d documents, got %d", len(items), length)
	}
}

func TestMsgpackToBSONDocumentsReadsAList(t *testing.T) {
	var buffer bytes.Buffer

	encoder := msgpack.NewEncoder(&buffer)

	if err := encoder.EncodeArrayLen(2); err != nil {
		t.Fatalf("encoding list: %v", err)
	}

	for _, value := range []int64{1, 2} {
		if err := encoder.EncodeMapLen(1); err != nil {
			t.Fatalf("encoding document: %v", err)
		}

		if err := encoder.EncodeString("n"); err != nil {
			t.Fatalf("encoding key: %v", err)
		}

		if err := encoder.EncodeInt(value); err != nil {
			t.Fatalf("encoding value: %v", err)
		}
	}

	documents, err := MsgpackToBSONDocuments(buffer.Bytes())

	if err != nil {
		t.Fatalf("converting list: %v", err)
	}

	if len(documents) != 2 {
		t.Fatalf("expected 2 documents, got %d", len(documents))
	}

	for index, document := range documents {
		raw, ok := document.(bson.Raw)

		if !ok {
			t.Fatalf("document %d is not bson.Raw", index)
		}

		if value, ok := raw.Lookup("n").Int32OK(); !ok || int(value) != index+1 {
			t.Errorf("document %d holds %v, expected %d", index, raw.Lookup("n"), index+1)
		}
	}
}

func TestRejectsUnknownObjectClass(t *testing.T) {
	var buffer bytes.Buffer

	encoder := msgpack.NewEncoder(&buffer)

	if err := encoder.EncodeMapLen(1); err != nil {
		t.Fatalf("encoding document: %v", err)
	}

	if err := encoder.EncodeString("value"); err != nil {
		t.Fatalf("encoding key: %v", err)
	}

	if err := encodeObjectHeader(encoder, `App\Model\User`, 0); err != nil {
		t.Fatalf("encoding envelope: %v", err)
	}

	if _, err := MsgpackToBSON(buffer.Bytes()); err == nil {
		t.Error("expected an error for an unknown object class, got none")
	}
}

// ext-msgpack does not repeat an object it has already written: the second
// appearance of the same PHP instance becomes {nil: 4, 0: <index>}, where the
// index counts every container written so far — maps, arrays, objects and
// references alike. Reusing one ObjectId variable across a document is ordinary
// user code, so these bytes are pinned with the layout PHP actually produces.
func TestResolvesRepeatedObjectInstances(t *testing.T) {
	const objectIdClass = "b553436f6e6375725c42736f6e5c4f626a6563744964"
	const objectIdValue = "a36f6964b8363931396533643161333637336433663464393133376133"

	envelope := "82c0" + objectIdClass + objectIdValue
	reference := "82c0040002"

	cases := map[string]struct {
		payload string
		keys    []string
	}{
		// {"x": <object>, "y": <reference to it>}
		"repeat in a map": {
			payload: "82a178" + envelope + "a179" + reference,
			keys:    []string{"x", "y"},
		},
		// {"x": <object>, "l": [<reference to it>]}
		"repeat inside a list": {
			payload: "82a178" + envelope + "a16c91" + "82c0040002",
			keys:    []string{"x"},
		},
	}

	for name, testCase := range cases {
		t.Run(name, func(t *testing.T) {
			data, err := hex.DecodeString(testCase.payload)

			if err != nil {
				t.Fatalf("hex: %v", err)
			}

			raw, err := MsgpackToBSON(data)

			if err != nil {
				t.Fatalf("MsgpackToBSON: %v", err)
			}

			for _, key := range testCase.keys {
				if _, ok := raw.Lookup(key).ObjectIDOK(); !ok {
					t.Errorf("key %q is %v, want an ObjectId", key, raw.Lookup(key))
				}
			}
		})
	}
}

func TestRejectsUnknownObjectReference(t *testing.T) {
	// A reference to an index nothing was recorded under.
	data, err := hex.DecodeString("81a178" + "82c004007f")

	if err != nil {
		t.Fatalf("hex: %v", err)
	}

	if _, err := MsgpackToBSON(data); err == nil {
		t.Error("expected an error for a dangling object reference, got none")
	}
}
