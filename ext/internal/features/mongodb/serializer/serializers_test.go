package serializer

import (
	"fmt"
	"reflect"
	"strings"
	"testing"
	"time"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/bson/primitive"
	"go.mongodb.org/mongo-driver/mongo"
)

func TestUnmarshalDocumentPreservesFieldAndElementOrder(t *testing.T) {
	objectID := primitive.NewObjectID()
	ts := time.Date(2024, time.May, 6, 7, 8, 9, 123456000, time.UTC)

	input := fmt.Sprintf(
		`{"first":1,"second":{"nestedA":"x","nestedB":2},"third":[{"arrDocFirst":true,"arrDocSecond":"%s"},3,{"when":{"$date":{"$numberLong":"%d"}}}],"fourth":{"$oid":"%s"}}`,
		utcDateTimeStringPrefix+ts.Format(dateFormat),
		ts.UnixMilli(),
		objectID.Hex(),
	)

	got, err := UnmarshalDocument(input)
	if err != nil {
		t.Fatalf("UnmarshalDocument() error = %v", err)
	}

	want := bson.D{
		{Key: "first", Value: int32(1)},
		{Key: "second", Value: bson.D{
			{Key: "nestedA", Value: "x"},
			{Key: "nestedB", Value: int32(2)},
		}},
		{Key: "third", Value: primitive.A{
			bson.D{
				{Key: "arrDocFirst", Value: true},
				{Key: "arrDocSecond", Value: primitive.NewDateTimeFromTime(ts)},
			},
			int32(3),
			bson.D{
				{Key: "when", Value: primitive.NewDateTimeFromTime(ts)},
			},
		}},
		{Key: "fourth", Value: objectID},
	}

	assertDeepEqual(t, got, want)
}

func TestUnmarshalDocumentNormalizesEmptyPayloads(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  interface{}
	}{
		{
			name:  "null becomes empty document",
			input: `null`,
			want:  bson.D{},
		},
		{
			name:  "empty array remains empty array",
			input: `[]`,
			want:  primitive.A{},
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got, err := UnmarshalDocument(tc.input)
			if err != nil {
				t.Fatalf("UnmarshalDocument() error = %v", err)
			}

			assertDeepEqual(t, got, tc.want)
		})
	}
}

func TestUnmarshalDocumentsPreservesTopLevelAndNestedOrder(t *testing.T) {
	input := `[
		{"doc":"first","payload":{"a":1,"b":2},"items":[{"x":1,"y":2}, {"x":3,"y":4}]},
		{"doc":"second","payload":{"c":3,"d":4},"items":[5,6,7]}
	]`

	got, err := UnmarshalDocuments(input)
	if err != nil {
		t.Fatalf("UnmarshalDocuments() error = %v", err)
	}

	want := []interface{}{
		bson.D{
			{Key: "doc", Value: "first"},
			{Key: "payload", Value: bson.D{
				{Key: "a", Value: int32(1)},
				{Key: "b", Value: int32(2)},
			}},
			{Key: "items", Value: primitive.A{
				bson.D{
					{Key: "x", Value: int32(1)},
					{Key: "y", Value: int32(2)},
				},
				bson.D{
					{Key: "x", Value: int32(3)},
					{Key: "y", Value: int32(4)},
				},
			}},
		},
		bson.D{
			{Key: "doc", Value: "second"},
			{Key: "payload", Value: bson.D{
				{Key: "c", Value: int32(3)},
				{Key: "d", Value: int32(4)},
			}},
			{Key: "items", Value: primitive.A{int32(5), int32(6), int32(7)}},
		},
	}

	assertDeepEqual(t, got, want)
}

func TestMarshalDocumentRoundTripPreservesOrder(t *testing.T) {
	objectID := primitive.NewObjectID()
	ts := time.Date(2025, time.January, 2, 3, 4, 5, 0, time.UTC)

	input := bson.D{
		{Key: "first", Value: "value"},
		{Key: "second", Value: bson.D{
			{Key: "nestedFirst", Value: int32(10)},
			{Key: "nestedSecond", Value: primitive.A{
				bson.D{
					{Key: "innerA", Value: "A"},
					{Key: "innerB", Value: "B"},
				},
				int32(2),
			}},
		}},
		{Key: "third", Value: objectID},
		{Key: "fourth", Value: primitive.NewDateTimeFromTime(ts)},
	}

	marshaled, err := MarshalDocument(input)
	if err != nil {
		t.Fatalf("MarshalDocument() error = %v", err)
	}

	got, err := UnmarshalDocument(marshaled)
	if err != nil {
		t.Fatalf("UnmarshalDocument() error = %v", err)
	}

	assertDeepEqual(t, got, input)
}

func TestUnmarshalBulkWriteModelsPreservesOperationAndFieldOrder(t *testing.T) {
	insertDoc := `{"z":1,"a":{"nested1":1,"nested2":2},"arr":[{"x":1,"y":2},2]}`
	updateOneFilter := `{"tenant":"t1","user":{"id":1,"name":"alice"}}`
	updateOneUpdate := `{"$set":{"profile":{"first":"Alice","last":"Smith"},"tags":["one","two"]},"$inc":{"version":1}}`
	updateManyFilter := `{"status":"active","scope":{"region":"eu","env":"prod"}}`
	updateManyUpdate := `{"$unset":{"legacy":""},"$set":{"updatedAt":{"$date":{"$numberLong":"1735689600000"}}}}`
	deleteOneFilter := `{"kind":"single","payload":{"left":1,"right":2}}`
	deleteManyFilter := `{"kind":"many","ids":[1,2,3]}`
	replaceOneFilter := `{"entity":"account","meta":{"tenant":"t1","project":"p1"}}`
	replaceOneReplacement := `{"name":"replacement","flags":[true,false],"doc":{"first":1,"second":2}}`

	input := fmt.Sprintf(`[
		{"type":"insertOne","model":{"document":%q}},
		{"type":"updateOne","model":{"filter":%q,"update":%q,"upsert":true}},
		{"type":"updateMany","model":{"filter":%q,"update":%q,"upsert":false}},
		{"type":"deleteOne","model":{"filter":%q}},
		{"type":"deleteMany","model":{"filter":%q}},
		{"type":"replaceOne","model":{"filter":%q,"replacement":%q,"upsert":true}}
	]`,
		insertDoc,
		updateOneFilter, updateOneUpdate,
		updateManyFilter, updateManyUpdate,
		deleteOneFilter,
		deleteManyFilter,
		replaceOneFilter, replaceOneReplacement,
	)

	got, err := UnmarshalBulkWriteModels(input)
	if err != nil {
		t.Fatalf("UnmarshalBulkWriteModels() error = %v", err)
	}

	if len(got) != 6 {
		t.Fatalf("len(models) = %d, want 6", len(got))
	}

	insertModel, ok := got[0].(*mongo.InsertOneModel)
	if !ok {
		t.Fatalf("models[0] type = %T, want *mongo.InsertOneModel", got[0])
	}
	assertDeepEqual(t, insertModel.Document, bson.D{
		{Key: "z", Value: int32(1)},
		{Key: "a", Value: bson.D{
			{Key: "nested1", Value: int32(1)},
			{Key: "nested2", Value: int32(2)},
		}},
		{Key: "arr", Value: primitive.A{
			bson.D{
				{Key: "x", Value: int32(1)},
				{Key: "y", Value: int32(2)},
			},
			int32(2),
		}},
	})

	updateOneModel, ok := got[1].(*mongo.UpdateOneModel)
	if !ok {
		t.Fatalf("models[1] type = %T, want *mongo.UpdateOneModel", got[1])
	}
	assertBoolPtrValue(t, updateOneModel.Upsert, true, "models[1].Upsert")
	assertDeepEqual(t, updateOneModel.Filter, bson.D{
		{Key: "tenant", Value: "t1"},
		{Key: "user", Value: bson.D{
			{Key: "id", Value: int32(1)},
			{Key: "name", Value: "alice"},
		}},
	})
	assertDeepEqual(t, updateOneModel.Update, bson.D{
		{Key: "$set", Value: bson.D{
			{Key: "profile", Value: bson.D{
				{Key: "first", Value: "Alice"},
				{Key: "last", Value: "Smith"},
			}},
			{Key: "tags", Value: primitive.A{"one", "two"}},
		}},
		{Key: "$inc", Value: bson.D{
			{Key: "version", Value: int32(1)},
		}},
	})

	updateManyModel, ok := got[2].(*mongo.UpdateManyModel)
	if !ok {
		t.Fatalf("models[2] type = %T, want *mongo.UpdateManyModel", got[2])
	}
	assertBoolPtrValue(t, updateManyModel.Upsert, false, "models[2].Upsert")
	assertDeepEqual(t, updateManyModel.Filter, bson.D{
		{Key: "status", Value: "active"},
		{Key: "scope", Value: bson.D{
			{Key: "region", Value: "eu"},
			{Key: "env", Value: "prod"},
		}},
	})
	assertDeepEqual(t, updateManyModel.Update, bson.D{
		{Key: "$unset", Value: bson.D{
			{Key: "legacy", Value: ""},
		}},
		{Key: "$set", Value: bson.D{
			{Key: "updatedAt", Value: primitive.NewDateTimeFromTime(time.UnixMilli(1735689600000).UTC())},
		}},
	})

	deleteOneModel, ok := got[3].(*mongo.DeleteOneModel)
	if !ok {
		t.Fatalf("models[3] type = %T, want *mongo.DeleteOneModel", got[3])
	}
	assertDeepEqual(t, deleteOneModel.Filter, bson.D{
		{Key: "kind", Value: "single"},
		{Key: "payload", Value: bson.D{
			{Key: "left", Value: int32(1)},
			{Key: "right", Value: int32(2)},
		}},
	})

	deleteManyModel, ok := got[4].(*mongo.DeleteManyModel)
	if !ok {
		t.Fatalf("models[4] type = %T, want *mongo.DeleteManyModel", got[4])
	}
	assertDeepEqual(t, deleteManyModel.Filter, bson.D{
		{Key: "kind", Value: "many"},
		{Key: "ids", Value: primitive.A{int32(1), int32(2), int32(3)}},
	})

	replaceOneModel, ok := got[5].(*mongo.ReplaceOneModel)
	if !ok {
		t.Fatalf("models[5] type = %T, want *mongo.ReplaceOneModel", got[5])
	}
	assertBoolPtrValue(t, replaceOneModel.Upsert, true, "models[5].Upsert")
	assertDeepEqual(t, replaceOneModel.Filter, bson.D{
		{Key: "entity", Value: "account"},
		{Key: "meta", Value: bson.D{
			{Key: "tenant", Value: "t1"},
			{Key: "project", Value: "p1"},
		}},
	})
	assertDeepEqual(t, replaceOneModel.Replacement, bson.D{
		{Key: "name", Value: "replacement"},
		{Key: "flags", Value: primitive.A{true, false}},
		{Key: "doc", Value: bson.D{
			{Key: "first", Value: int32(1)},
			{Key: "second", Value: int32(2)},
		}},
	})
}

func TestUnmarshalBulkWriteModelsReturnsHelpfulErrors(t *testing.T) {
	t.Run("unknown model type", func(t *testing.T) {
		_, err := UnmarshalBulkWriteModels(`[{"type":"mystery","model":{}}]`)
		if err == nil {
			t.Fatal("UnmarshalBulkWriteModels() error = nil, want error")
		}

		if !strings.Contains(err.Error(), "unknown type of model: mystery") {
			t.Fatalf("error = %q, want substring %q", err.Error(), "unknown type of model: mystery")
		}
	})

	t.Run("invalid nested extjson is attributed to operation", func(t *testing.T) {
		_, err := UnmarshalBulkWriteModels(`[{"type":"insertOne","model":{"document":"{"}}]`)
		if err == nil {
			t.Fatal("UnmarshalBulkWriteModels() error = nil, want error")
		}

		if !strings.Contains(err.Error(), "insertOne document") {
			t.Fatalf("error = %q, want substring %q", err.Error(), "insertOne document")
		}
	})
}

func assertBoolPtrValue(t *testing.T, got *bool, want bool, field string) {
	t.Helper()

	if got == nil {
		t.Fatalf("%s = nil, want %v", field, want)
	}

	if *got != want {
		t.Fatalf("%s = %v, want %v", field, *got, want)
	}
}

func assertDeepEqual(t *testing.T, got interface{}, want interface{}) {
	t.Helper()

	if !reflect.DeepEqual(got, want) {
		t.Fatalf("value mismatch\n got: %#v\nwant: %#v", got, want)
	}
}
