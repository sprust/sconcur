package amqp_feature

import (
	"testing"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
)

func TestMapToTableNormalizesTheNumbersMessagePackDecodesInto(t *testing.T) {
	table := mapToTable(map[string]any{
		"int":     7,
		"int8":    int8(7),
		"uint64":  uint64(7),
		"float32": float32(1.5),
		"float64": 1.5,
		"string":  "text",
		"bool":    true,
		"bytes":   []byte("raw"),
	})

	// The driver writes int64/float64/string/bool; an unsigned or narrow integer would be
	// rejected as an unsupported field type.
	expected := amqp091.Table{
		"int":     int64(7),
		"int8":    int64(7),
		"uint64":  int64(7),
		"float32": 1.5,
		"float64": 1.5,
		"string":  "text",
		"bool":    true,
		"bytes":   "raw",
	}

	for name, want := range expected {
		if table[name] != want {
			t.Fatalf("%s = %#v (%T), want %#v (%T)", name, table[name], table[name], want, want)
		}
	}
}

func TestMapToTableConvertsNestedValues(t *testing.T) {
	table := mapToTable(map[string]any{
		"nested": map[string]any{"count": 3},
		"list":   []any{1, "two"},
	})

	nested, ok := table["nested"].(amqp091.Table)

	if !ok {
		t.Fatalf("nested = %T, want amqp091.Table", table["nested"])
	}

	if nested["count"] != int64(3) {
		t.Fatalf("nested count = %#v, want int64(3)", nested["count"])
	}

	list, ok := table["list"].([]any)

	if !ok {
		t.Fatalf("list = %T, want []any", table["list"])
	}

	if len(list) != 2 || list[0] != int64(1) || list[1] != "two" {
		t.Fatalf("list = %#v, want [int64(1) two]", list)
	}
}

func TestMapToTableIsNilWhenThereIsNothingToSend(t *testing.T) {
	if mapToTable(nil) != nil {
		t.Fatal("no arguments must produce no table")
	}

	if mapToTable(map[string]any{}) != nil {
		t.Fatal("an empty argument map must produce no table")
	}
}

func TestTableToMapGivesPhpValuesMessagePackCanCarry(t *testing.T) {
	values := tableToMap(amqp091.Table{
		"bytes":  []byte("raw"),
		"nested": amqp091.Table{"inner": "value"},
		"list":   []any{amqp091.Decimal{Scale: 1, Value: 15}},
	})

	if values["bytes"] != "raw" {
		t.Fatalf("bytes = %#v, want raw", values["bytes"])
	}

	nested, ok := values["nested"].(map[string]any)

	if !ok || nested["inner"] != "value" {
		t.Fatalf("nested = %#v, want map with inner=value", values["nested"])
	}

	list, ok := values["list"].([]any)

	if !ok || len(list) != 1 {
		t.Fatalf("list = %#v, want one value", values["list"])
	}

	// A decimal inside a list keeps its kind too.
	decimal, ok := list[0].(map[string]any)

	if !ok || decimal["\x00amqp"] != "D" {
		t.Fatalf("list[0] = %#v, want the tagged decimal shape", list[0])
	}
}

func TestATaggedDecimalAndTimestampBecomeRealFieldValues(t *testing.T) {
	table := mapToTable(map[string]any{
		"decimal":   map[string]any{"\x00amqp": "D", "e": 2, "s": 314},
		"timestamp": map[string]any{"\x00amqp": "T", "v": int64(1_700_000_000)},
		"plain":     map[string]any{"nested": 1},
	})

	decimal, ok := table["decimal"].(amqp091.Decimal)

	if !ok {
		t.Fatalf("decimal = %T, want amqp091.Decimal", table["decimal"])
	}

	if decimal.Scale != 2 || decimal.Value != 314 {
		t.Fatalf("decimal = %#v, want scale 2 value 314", decimal)
	}

	timestamp, ok := table["timestamp"].(time.Time)

	if !ok {
		t.Fatalf("timestamp = %T, want time.Time", table["timestamp"])
	}

	if timestamp.Unix() != 1_700_000_000 {
		t.Fatalf("timestamp = %d, want 1700000000", timestamp.Unix())
	}

	// A map that is not tagged stays a nested table.
	if _, ok := table["plain"].(amqp091.Table); !ok {
		t.Fatalf("plain = %T, want amqp091.Table", table["plain"])
	}
}

func TestADecimalAndTimestampGoBackToPhpTagged(t *testing.T) {
	values := tableToMap(amqp091.Table{
		"decimal":   amqp091.Decimal{Scale: 2, Value: 314},
		"timestamp": time.Unix(1_700_000_000, 0),
	})

	decimal, ok := values["decimal"].(map[string]any)

	if !ok || decimal["\x00amqp"] != "D" || decimal["e"] != int64(2) || decimal["s"] != int64(314) {
		t.Fatalf("decimal = %#v, want the tagged decimal shape", values["decimal"])
	}

	timestamp, ok := values["timestamp"].(map[string]any)

	if !ok || timestamp["\x00amqp"] != "T" || timestamp["v"] != int64(1_700_000_000) {
		t.Fatalf("timestamp = %#v, want the tagged timestamp shape", values["timestamp"])
	}
}

func TestTimestampRoundTrip(t *testing.T) {
	if timestampToUnix(time.Time{}) != 0 {
		t.Fatal("a message with no timestamp must report 0")
	}

	if !unixToTimestamp(0).IsZero() {
		t.Fatal("0 seconds must mean no timestamp")
	}

	seconds := int64(1_700_000_000)

	if unixToTimestamp(seconds).Unix() != seconds {
		t.Fatalf("timestamp round trip lost %d", seconds)
	}
}
