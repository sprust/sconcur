package sql_feature

import (
	"testing"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

func TestNormalizeBindingCollapsesNumericKinds(t *testing.T) {
	cases := map[string]struct {
		in   any
		want any
	}{
		"int8":    {int8(7), int64(7)},
		"int":     {int(7), int64(7)},
		"uint32":  {uint32(7), int64(7)},
		"uint64":  {uint64(7), int64(7)},
		"float32": {float32(1.5), float64(1.5)},
		"int64":   {int64(9), int64(9)},
		"string":  {"x", "x"},
		"bool":    {true, true},
		"nil":     {nil, nil},
	}

	for name, testCase := range cases {
		t.Run(name, func(t *testing.T) {
			got := normalizeBinding(testCase.in)

			if got != testCase.want {
				t.Fatalf("normalizeBinding(%v) = %v (%T), want %v (%T)", testCase.in, got, got, testCase.want, testCase.want)
			}
		})
	}
}

func TestNormalizeColumnValue(t *testing.T) {
	if got := normalizeColumnValue([]byte("hello")); got != "hello" {
		t.Fatalf("[]byte not rendered as string: %v (%T)", got, got)
	}

	moment := time.Date(2026, 6, 16, 10, 30, 0, 0, time.UTC)

	if got := normalizeColumnValue(moment); got != moment.Format(time.RFC3339Nano) {
		t.Fatalf("time not rendered RFC3339: %v", got)
	}

	if got := normalizeColumnValue(int64(42)); got != int64(42) {
		t.Fatalf("int64 should pass through: %v (%T)", got, got)
	}
}

func TestMarshalBatchEmptyDecodesToList(t *testing.T) {
	encoded, err := marshalBatch(nil)

	if err != nil {
		t.Fatalf("marshalBatch error: %v", err)
	}

	var decoded []map[string]any

	if err := msgpack.Unmarshal([]byte(encoded), &decoded); err != nil {
		t.Fatalf("decode error: %v", err)
	}

	if decoded == nil || len(decoded) != 0 {
		t.Fatalf("expected empty list, got %#v", decoded)
	}
}

func TestMarshalBatchRoundTrip(t *testing.T) {
	encoded, err := marshalBatch([]map[string]any{
		{"id": int64(1), "name": "Ann"},
		{"id": int64(2), "name": "Bob"},
	})

	if err != nil {
		t.Fatalf("marshalBatch error: %v", err)
	}

	var decoded []map[string]any

	if err := msgpack.Unmarshal([]byte(encoded), &decoded); err != nil {
		t.Fatalf("decode error: %v", err)
	}

	if len(decoded) != 2 || decoded[1]["name"] != "Bob" {
		t.Fatalf("unexpected decode: %#v", decoded)
	}
}
