package serializer

import (
	"testing"

	"go.mongodb.org/mongo-driver/v2/bson"
)

func TestUnmarshalDocumentReturnsRawBSON(t *testing.T) {
	docBytes, err := bson.Marshal(bson.D{{Key: "a", Value: int32(1)}, {Key: "b", Value: "x"}})
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	got, err := UnmarshalDocument(docBytes)
	if err != nil {
		t.Fatalf("UnmarshalDocument() error = %v", err)
	}

	raw, ok := got.(bson.Raw)
	if !ok {
		t.Fatalf("UnmarshalDocument() = %T, want bson.Raw", got)
	}

	if string(raw) != string(docBytes) {
		t.Fatalf("UnmarshalDocument() returned modified bytes")
	}
}

func TestMarshalDocumentPassesRawThrough(t *testing.T) {
	docBytes, _ := bson.Marshal(bson.D{{Key: "x", Value: int32(1)}})

	got, err := MarshalDocument(bson.Raw(docBytes))
	if err != nil {
		t.Fatalf("MarshalDocument() error = %v", err)
	}

	if got != string(docBytes) {
		t.Fatalf("MarshalDocument(bson.Raw) did not pass bytes through")
	}
}

func TestMarshalDocumentMarshalsStruct(t *testing.T) {
	got, err := MarshalDocument(bson.D{{Key: "n", Value: int64(5)}})
	if err != nil {
		t.Fatalf("MarshalDocument() error = %v", err)
	}

	var back bson.D
	if err := bson.Unmarshal([]byte(got), &back); err != nil {
		t.Fatalf("result is not valid BSON: %v", err)
	}

	if len(back) != 1 || back[0].Key != "n" {
		t.Fatalf("unexpected marshaled document: %v", back)
	}
}

func TestUnmarshalDocumentsSplitsArray(t *testing.T) {
	_, arrBytes, err := bson.MarshalValue(bson.A{
		bson.D{{Key: "a", Value: int32(1)}},
		bson.D{{Key: "b", Value: int32(2)}},
	})
	if err != nil {
		t.Fatalf("marshal array: %v", err)
	}

	docs, err := UnmarshalDocuments(arrBytes)
	if err != nil {
		t.Fatalf("UnmarshalDocuments() error = %v", err)
	}

	if len(docs) != 2 {
		t.Fatalf("UnmarshalDocuments() len = %d, want 2", len(docs))
	}

	for _, doc := range docs {
		if _, ok := doc.(bson.Raw); !ok {
			t.Fatalf("element type = %T, want bson.Raw", doc)
		}
	}
}

func TestMarshalDocumentBatchRawWrapsDocuments(t *testing.T) {
	doc0, _ := bson.Marshal(bson.D{{Key: "a", Value: int32(1)}})
	doc1, _ := bson.Marshal(bson.D{{Key: "b", Value: int32(2)}})

	packed, err := MarshalDocumentBatchRaw([]bson.Raw{doc0, doc1})
	if err != nil {
		t.Fatalf("MarshalDocumentBatchRaw() error = %v", err)
	}

	var wrapper struct {
		D bson.A `bson:"d"`
	}
	if err := bson.Unmarshal([]byte(packed), &wrapper); err != nil {
		t.Fatalf("wrapper is not valid BSON: %v", err)
	}

	if len(wrapper.D) != 2 {
		t.Fatalf("wrapper has %d documents, want 2", len(wrapper.D))
	}
}

func TestUnmarshalDocumentsRejectsNonDocumentElement(t *testing.T) {
	arrayBytes, err := bson.Marshal(bson.D{
		{Key: "0", Value: bson.D{{Key: "title", Value: "valid document"}}},
		{Key: "1", Value: "scalar instead of document"},
	})
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	_, err = UnmarshalDocuments(arrayBytes)

	if err == nil {
		t.Fatal("UnmarshalDocuments() must return an error for a non-document element, not panic")
	}
}
