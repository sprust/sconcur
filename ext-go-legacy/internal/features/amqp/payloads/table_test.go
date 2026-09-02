package payloads

import (
	"testing"

	"github.com/vmihailenco/msgpack/v5"
)

func TestAnEmptyPhpArrayDecodesAsAnEmptyTable(t *testing.T) {
	// PHP has one array type: an empty argument list arrives as an empty MessagePack
	// array, not as an empty map.
	encoded, err := msgpack.Marshal([]any{})

	if err != nil {
		t.Fatalf("marshal error: %v", err)
	}

	var table Table

	if err := msgpack.Unmarshal(encoded, &table); err != nil {
		t.Fatalf("unmarshal error: %v", err)
	}

	if len(table) != 0 {
		t.Fatalf("table = %#v, want empty", table)
	}
}

func TestAMapDecodesIntoTheTable(t *testing.T) {
	encoded, err := msgpack.Marshal(map[string]any{"x-max-length": 10})

	if err != nil {
		t.Fatalf("marshal error: %v", err)
	}

	var table Table

	if err := msgpack.Unmarshal(encoded, &table); err != nil {
		t.Fatalf("unmarshal error: %v", err)
	}

	if len(table) != 1 {
		t.Fatalf("table = %#v, want one value", table)
	}

	// Decoded loosely: every integer arrives as an int64, whichever width MessagePack
	// packed it in.
	if table["x-max-length"] != int64(10) {
		t.Fatalf("x-max-length = %#v (%T), want the value that was sent", table["x-max-length"], table["x-max-length"])
	}
}

func TestNilDecodesAsNoTable(t *testing.T) {
	encoded, err := msgpack.Marshal(nil)

	if err != nil {
		t.Fatalf("marshal error: %v", err)
	}

	var table Table

	if err := msgpack.Unmarshal(encoded, &table); err != nil {
		t.Fatalf("unmarshal error: %v", err)
	}

	if table != nil {
		t.Fatalf("table = %#v, want nil", table)
	}
}

func TestANonEmptyArrayIsRejected(t *testing.T) {
	encoded, err := msgpack.Marshal([]any{"value"})

	if err != nil {
		t.Fatalf("marshal error: %v", err)
	}

	var table Table

	if err := msgpack.Unmarshal(encoded, &table); err == nil {
		t.Fatal("a list of values is not a field table and must be reported")
	}
}
