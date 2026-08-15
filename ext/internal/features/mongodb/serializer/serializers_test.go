package serializer

import (
	"bytes"
	"testing"

	"github.com/vmihailenco/msgpack/v5"
	"go.mongodb.org/mongo-driver/v2/bson"
)

// encodeMsgpackDocument builds the MessagePack bytes the PHP side would send for
// the given key/value pairs, written with the low-level encoder so these tests do
// not lean on the outgoing converter to check the incoming one.
func encodeMsgpackDocument(t *testing.T, pairs ...interface{}) []byte {
	t.Helper()

	if len(pairs)%2 != 0 {
		t.Fatalf("pairs must come in twos, got %d", len(pairs))
	}

	var buffer bytes.Buffer

	encoder := msgpack.NewEncoder(&buffer)

	if err := encoder.EncodeMapLen(len(pairs) / 2); err != nil {
		t.Fatalf("encoding map: %v", err)
	}

	for index := 0; index < len(pairs); index += 2 {
		if err := encoder.EncodeString(pairs[index].(string)); err != nil {
			t.Fatalf("encoding key: %v", err)
		}

		if err := encoder.Encode(pairs[index+1]); err != nil {
			t.Fatalf("encoding value: %v", err)
		}
	}

	return buffer.Bytes()
}

func TestPayloadDocumentConvertsToRawBSON(t *testing.T) {
	got, err := PayloadDocument(encodeMsgpackDocument(t, "a", int32(1), "b", "x"))

	if err != nil {
		t.Fatalf("PayloadDocument() error = %v", err)
	}

	raw, ok := got.(bson.Raw)

	if !ok {
		t.Fatalf("PayloadDocument() = %T, want bson.Raw", got)
	}

	if value, ok := raw.Lookup("a").Int32OK(); !ok || value != 1 {
		t.Errorf("key a = %v, want 1", raw.Lookup("a"))
	}

	if value, ok := raw.Lookup("b").StringValueOK(); !ok || value != "x" {
		t.Errorf("key b = %v, want x", raw.Lookup("b"))
	}
}

func TestPayloadDocumentAcceptsEmptyPayload(t *testing.T) {
	got, err := PayloadDocument(nil)

	if err != nil {
		t.Fatalf("PayloadDocument(nil) error = %v", err)
	}

	if raw, ok := got.(bson.Raw); !ok || len(raw) != 0 {
		t.Errorf("PayloadDocument(nil) = %v, want an empty bson.Raw", got)
	}
}

func TestMarshalDocumentEncodesRawAsMsgpack(t *testing.T) {
	document, err := bson.Marshal(bson.D{{Key: "x", Value: int32(1)}})

	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	got, err := MarshalDocument(bson.Raw(document))

	if err != nil {
		t.Fatalf("MarshalDocument() error = %v", err)
	}

	back, err := MsgpackToBSON([]byte(got))

	if err != nil {
		t.Fatalf("result is not MessagePack: %v", err)
	}

	if value, ok := back.Lookup("x").Int32OK(); !ok || value != 1 {
		t.Errorf("key x = %v, want 1", back.Lookup("x"))
	}
}

func TestMarshalDocumentEncodesDriverStructs(t *testing.T) {
	got, err := MarshalDocument(bson.D{{Key: "n", Value: int64(5)}})

	if err != nil {
		t.Fatalf("MarshalDocument() error = %v", err)
	}

	back, err := MsgpackToBSON([]byte(got))

	if err != nil {
		t.Fatalf("result is not MessagePack: %v", err)
	}

	// 5 fits in an int32, and the converter narrows on the way back, so read the
	// value rather than the width.
	if value, ok := back.Lookup("n").AsInt64OK(); !ok || value != 5 {
		t.Errorf("key n = %v, want 5", back.Lookup("n"))
	}
}

func TestPayloadDocumentsSplitsAList(t *testing.T) {
	var buffer bytes.Buffer

	encoder := msgpack.NewEncoder(&buffer)

	if err := encoder.EncodeArrayLen(2); err != nil {
		t.Fatalf("encoding list: %v", err)
	}

	buffer.Write(encodeMsgpackDocument(t, "a", int32(1)))
	buffer.Write(encodeMsgpackDocument(t, "b", int32(2)))

	documents, err := PayloadDocuments(buffer.Bytes())

	if err != nil {
		t.Fatalf("PayloadDocuments() error = %v", err)
	}

	if len(documents) != 2 {
		t.Fatalf("PayloadDocuments() len = %d, want 2", len(documents))
	}

	for index, document := range documents {
		if _, ok := document.(bson.Raw); !ok {
			t.Fatalf("element %d type = %T, want bson.Raw", index, document)
		}
	}
}

func TestPayloadDocumentsRejectsNonDocumentElement(t *testing.T) {
	var buffer bytes.Buffer

	encoder := msgpack.NewEncoder(&buffer)

	if err := encoder.EncodeArrayLen(2); err != nil {
		t.Fatalf("encoding list: %v", err)
	}

	buffer.Write(encodeMsgpackDocument(t, "title", "valid document"))

	if err := encoder.EncodeString("scalar instead of document"); err != nil {
		t.Fatalf("encoding scalar: %v", err)
	}

	if _, err := PayloadDocuments(buffer.Bytes()); err == nil {
		t.Fatal("PayloadDocuments() must return an error for a non-document element, not panic")
	}
}
