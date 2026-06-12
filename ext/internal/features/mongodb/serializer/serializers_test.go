package serializer

import (
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/vmihailenco/msgpack/v5"
	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/bson/primitive"
	"go.mongodb.org/mongo-driver/mongo"
)

func TestUnmarshalDocumentPreservesFieldAndElementOrder(t *testing.T) {
	objectID := primitive.NewObjectID()
	ts := time.Date(2024, time.May, 6, 7, 8, 9, 123456000, time.UTC)

	input := mustPackValue(t, bson.D{
		{Key: "first", Value: 1},
		{Key: "second", Value: bson.D{
			{Key: "nestedA", Value: "x"},
			{Key: "nestedB", Value: 2},
		}},
		{Key: "third", Value: []interface{}{
			bson.D{
				{Key: "arrDocFirst", Value: true},
				{Key: "arrDocSecond", Value: utcDateTimeStringPrefix + ts.Format(dateParseFormat)},
			},
			3,
			bson.D{
				{Key: "when", Value: utcDateTimeStringPrefix + ts.Format(dateParseFormat)},
			},
		}},
		{Key: "fourth", Value: objectIdStringPrefix + objectID.Hex()},
	})

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

// A whole-second timestamp must serialize with an explicit ".000" millisecond part.
// time.RFC3339Nano drops the fraction for zero sub-second values ("...:55Z"), which the
// PHP side (DATE_RFC3339_EXTENDED) cannot parse. See dateOutputFormat.
func TestMarshalRecursiveDateTimeAlwaysEmitsMilliseconds(t *testing.T) {
	wholeSecond := time.Date(2026, time.June, 12, 6, 44, 55, 0, time.UTC)

	got := marshalRecursive(primitive.NewDateTimeFromTime(wholeSecond))

	want := utcDateTimeStringPrefix + "2026-06-12T06:44:55.000Z"

	if got != want {
		t.Fatalf("marshalRecursive(zero-ms DateTime) = %q, want %q", got, want)
	}
}

func TestUnmarshalDocumentNormalizesEmptyPayloads(t *testing.T) {
	tests := []struct {
		name  string
		input []byte
		want  interface{}
	}{
		{
			name:  "null becomes empty document",
			input: mustPackNil(t),
			want:  bson.D{},
		},
		{
			name:  "empty array remains empty array",
			input: mustPackValue(t, []interface{}{}),
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
	input := mustPackValue(t, []interface{}{
		bson.D{
			{Key: "doc", Value: "first"},
			{Key: "payload", Value: bson.D{
				{Key: "a", Value: 1},
				{Key: "b", Value: 2},
			}},
			{Key: "items", Value: []interface{}{
				bson.D{
					{Key: "x", Value: 1},
					{Key: "y", Value: 2},
				},
				bson.D{
					{Key: "x", Value: 3},
					{Key: "y", Value: 4},
				},
			}},
		},
		bson.D{
			{Key: "doc", Value: "second"},
			{Key: "payload", Value: bson.D{
				{Key: "c", Value: 3},
				{Key: "d", Value: 4},
			}},
			{Key: "items", Value: []interface{}{5, 6, 7}},
		},
	})

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

	got, err := UnmarshalDocument([]byte(marshaled))
	if err != nil {
		t.Fatalf("UnmarshalDocument() error = %v", err)
	}

	assertDeepEqual(t, got, input)
}

func TestUnmarshalBulkWriteModelsPreservesOperationAndFieldOrder(t *testing.T) {
	insertDoc := mustPackValue(t, bson.D{
		{Key: "z", Value: 1},
		{Key: "a", Value: bson.D{
			{Key: "nested1", Value: 1},
			{Key: "nested2", Value: 2},
		}},
		{Key: "arr", Value: []interface{}{
			bson.D{
				{Key: "x", Value: 1},
				{Key: "y", Value: 2},
			},
			2,
		}},
	})
	updateOneFilter := mustPackValue(t, bson.D{
		{Key: "tenant", Value: "t1"},
		{Key: "user", Value: bson.D{
			{Key: "id", Value: 1},
			{Key: "name", Value: "alice"},
		}},
	})
	updateOneUpdate := mustPackValue(t, bson.D{
		{Key: "$set", Value: bson.D{
			{Key: "profile", Value: bson.D{
				{Key: "first", Value: "Alice"},
				{Key: "last", Value: "Smith"},
			}},
			{Key: "tags", Value: []interface{}{"one", "two"}},
		}},
		{Key: "$inc", Value: bson.D{
			{Key: "version", Value: 1},
		}},
	})
	updateManyFilter := mustPackValue(t, bson.D{
		{Key: "status", Value: "active"},
		{Key: "scope", Value: bson.D{
			{Key: "region", Value: "eu"},
			{Key: "env", Value: "prod"},
		}},
	})
	updateManyUpdate := mustPackValue(t, bson.D{
		{Key: "$unset", Value: bson.D{
			{Key: "legacy", Value: ""},
		}},
		{Key: "$set", Value: bson.D{
			{Key: "updatedAt", Value: utcDateTimeStringPrefix + time.UnixMilli(1735689600000).UTC().Format(dateParseFormat)},
		}},
	})
	deleteOneFilter := mustPackValue(t, bson.D{
		{Key: "kind", Value: "single"},
		{Key: "payload", Value: bson.D{
			{Key: "left", Value: 1},
			{Key: "right", Value: 2},
		}},
	})
	deleteManyFilter := mustPackValue(t, bson.D{
		{Key: "kind", Value: "many"},
		{Key: "ids", Value: []interface{}{1, 2, 3}},
	})
	replaceOneFilter := mustPackValue(t, bson.D{
		{Key: "entity", Value: "account"},
		{Key: "meta", Value: bson.D{
			{Key: "tenant", Value: "t1"},
			{Key: "project", Value: "p1"},
		}},
	})
	replaceOneReplacement := mustPackValue(t, bson.D{
		{Key: "name", Value: "replacement"},
		{Key: "flags", Value: []interface{}{true, false}},
		{Key: "doc", Value: bson.D{
			{Key: "first", Value: 1},
			{Key: "second", Value: 2},
		}},
	})

	input := mustPackValue(t, []interface{}{
		map[string]interface{}{
			"type": "insertOne",
			"model": map[string]interface{}{
				"document": insertDoc,
			},
		},
		map[string]interface{}{
			"type": "updateOne",
			"model": map[string]interface{}{
				"filter": updateOneFilter,
				"update": updateOneUpdate,
				"upsert": true,
			},
		},
		map[string]interface{}{
			"type": "updateMany",
			"model": map[string]interface{}{
				"filter": updateManyFilter,
				"update": updateManyUpdate,
				"upsert": false,
			},
		},
		map[string]interface{}{
			"type": "deleteOne",
			"model": map[string]interface{}{
				"filter": deleteOneFilter,
			},
		},
		map[string]interface{}{
			"type": "deleteMany",
			"model": map[string]interface{}{
				"filter": deleteManyFilter,
			},
		},
		map[string]interface{}{
			"type": "replaceOne",
			"model": map[string]interface{}{
				"filter":      replaceOneFilter,
				"replacement": replaceOneReplacement,
				"upsert":      true,
			},
		},
	})

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
		input := mustPackValue(t, []interface{}{
			map[string]interface{}{
				"type":  "mystery",
				"model": map[string]interface{}{},
			},
		})

		_, err := UnmarshalBulkWriteModels(input)
		if err == nil {
			t.Fatal("UnmarshalBulkWriteModels() error = nil, want error")
		}

		if !strings.Contains(err.Error(), "unknown type of model: mystery") {
			t.Fatalf("error = %q, want substring %q", err.Error(), "unknown type of model: mystery")
		}
	})

	t.Run("invalid nested payload is attributed to operation", func(t *testing.T) {
		input := mustPackValue(t, []interface{}{
			map[string]interface{}{
				"type": "insertOne",
				"model": map[string]interface{}{
					"document": []byte{0xc1},
				},
			},
		})

		_, err := UnmarshalBulkWriteModels(input)
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

	gotNormalized := normalizeForComparison(got)
	wantNormalized := normalizeForComparison(want)

	if !reflect.DeepEqual(gotNormalized, wantNormalized) {
		t.Fatalf("value mismatch\n got: %#v\nwant: %#v", got, want)
	}
}

func normalizeForComparison(value interface{}) interface{} {
	switch v := value.(type) {
	case bson.D:
		result := make([]interface{}, len(v))

		for i, item := range v {
			result[i] = []interface{}{
				item.Key,
				normalizeForComparison(item.Value),
			}
		}

		return result
	case primitive.A:
		result := make([]interface{}, len(v))

		for i, item := range v {
			result[i] = normalizeForComparison(item)
		}

		return result
	case []interface{}:
		result := make([]interface{}, len(v))

		for i, item := range v {
			result[i] = normalizeForComparison(item)
		}

		return result
	case primitive.DateTime:
		return int64(v)
	case int:
		return int64(v)
	case int8:
		return int64(v)
	case int16:
		return int64(v)
	case int32:
		return int64(v)
	case int64:
		return v
	case uint:
		return uint64(v)
	case uint8:
		return uint64(v)
	case uint16:
		return uint64(v)
	case uint32:
		return uint64(v)
	case uint64:
		return v
	default:
		return v
	}
}

func mustPackNil(t *testing.T) []byte {
	t.Helper()

	packed, err := msgpack.Marshal(nil)
	if err != nil {
		t.Fatalf("msgpack.Marshal(nil) error = %v", err)
	}

	return packed
}

func mustPackValue(t *testing.T, value interface{}) []byte {
	t.Helper()

	packed, err := msgpack.Marshal(encodeMessagePackValue(value))
	if err != nil {
		t.Fatalf("msgpack.Marshal() error = %v", err)
	}

	return packed
}

func encodeMessagePackValue(value interface{}) interface{} {
	switch v := value.(type) {
	case bson.D:
		items := make([]interface{}, 0, len(v))

		for _, element := range v {
			items = append(items, []interface{}{
				element.Key,
				encodeMessagePackValue(element.Value),
			})
		}

		return map[string]interface{}{
			orderedMapMarker: items,
		}
	case primitive.A:
		result := make([]interface{}, len(v))

		for i, item := range v {
			result[i] = encodeMessagePackValue(item)
		}

		return result
	case []interface{}:
		result := make([]interface{}, len(v))

		for i, item := range v {
			result[i] = encodeMessagePackValue(item)
		}

		return result
	case map[string]interface{}:
		result := make(map[string]interface{}, len(v))

		for key, item := range v {
			result[key] = encodeMessagePackValue(item)
		}

		return result
	default:
		return v
	}
}
