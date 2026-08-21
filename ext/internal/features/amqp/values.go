package amqp_feature

import (
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
)

// The tagged shape an AMQPDecimal and an AMQPTimestamp travel in. AMQP 0-9-1 has field
// kinds of its own for both (D and T), and ext-amqp writes them, so a value published here
// must arrive at any other client — and come back to PHP as the object it was sent as —
// rather than flattened into a float or an integer.
//
// PHP: SConcur\Features\Amqp\Support\TableCodec.
const (
	// taggedKind names the kind of a tagged value. It starts with a NUL byte, which no
	// AMQP field name may contain, so an application's own header can never be mistaken
	// for one.
	taggedKind = "\x00amqp"
	// taggedDecimal is an AMQP decimal: significand scaled down by 10^exponent.
	taggedDecimal = "D"
	// taggedTimestamp is an AMQP timestamp, in seconds since the Unix epoch.
	taggedTimestamp = "T"
	// taggedExponent, taggedSignificand and taggedValue are the fields the two kinds
	// carry.
	taggedExponent    = "e"
	taggedSignificand = "s"
	taggedValue       = "v"
)

// mapToTable turns the arguments and headers PHP sent into an AMQP field table. The
// numeric types MessagePack decodes into are normalized to the ones the protocol can
// write — an unsigned or narrow integer would otherwise be rejected by the driver as an
// unsupported field type.
func mapToTable(values map[string]any) amqp091.Table {
	if len(values) == 0 {
		return nil
	}

	table := make(amqp091.Table, len(values))

	for name, value := range values {
		table[name] = toTableValue(value)
	}

	return table
}

func toTableValue(value any) any {
	switch typed := value.(type) {
	case nil, bool, string, float64:
		return typed
	case float32:
		return float64(typed)
	case int:
		return int64(typed)
	case int8:
		return int64(typed)
	case int16:
		return int64(typed)
	case int32:
		return int64(typed)
	case int64:
		return typed
	case uint:
		return int64(typed)
	case uint8:
		return int64(typed)
	case uint16:
		return int64(typed)
	case uint32:
		return int64(typed)
	case uint64:
		return int64(typed)
	case []byte:
		return string(typed)
	case map[string]any:
		if tagged, ok := taggedValueOf(typed); ok {
			return tagged
		}

		return mapToTable(typed)
	case []any:
		converted := make([]any, 0, len(typed))

		for _, item := range typed {
			converted = append(converted, toTableValue(item))
		}

		return converted
	default:
		return typed
	}
}

// taggedValueOf recognizes the map an AMQPDecimal or an AMQPTimestamp was encoded into and
// rebuilds the AMQP field value it stands for.
func taggedValueOf(values map[string]any) (any, bool) {
	kind, ok := values[taggedKind].(string)

	if !ok {
		return nil, false
	}

	switch kind {
	case taggedDecimal:
		return amqp091.Decimal{
			Scale: uint8(intFromValue(values[taggedExponent])),
			Value: int32(intFromValue(values[taggedSignificand])),
		}, true
	case taggedTimestamp:
		return time.Unix(intFromValue(values[taggedValue]), 0), true
	default:
		return nil, false
	}
}

// intFromValue reads back an integer whichever of its numeric types MessagePack decoded it
// into.
func intFromValue(value any) int64 {
	switch typed := value.(type) {
	case int:
		return int64(typed)
	case int8:
		return int64(typed)
	case int16:
		return int64(typed)
	case int32:
		return int64(typed)
	case int64:
		return typed
	case uint:
		return int64(typed)
	case uint8:
		return int64(typed)
	case uint16:
		return int64(typed)
	case uint32:
		return int64(typed)
	case uint64:
		return int64(typed)
	case float64:
		return int64(typed)
	default:
		return 0
	}
}

// tableToMap turns an AMQP field table into values MessagePack can carry. A decimal and a
// timestamp keep their kind, in the tagged shape PHP turns back into AMQPDecimal and
// AMQPTimestamp — that is what the extension hands to an application, and dropping to a
// scalar would silently change the type of a header on the way through.
func tableToMap(table amqp091.Table) map[string]any {
	if len(table) == 0 {
		return nil
	}

	values := make(map[string]any, len(table))

	for name, value := range table {
		values[name] = fromTableValue(value)
	}

	return values
}

func fromTableValue(value any) any {
	switch typed := value.(type) {
	case time.Time:
		return map[string]any{
			taggedKind:  taggedTimestamp,
			taggedValue: typed.Unix(),
		}
	case amqp091.Decimal:
		return map[string]any{
			taggedKind:        taggedDecimal,
			taggedExponent:    int64(typed.Scale),
			taggedSignificand: int64(typed.Value),
		}
	case []byte:
		return string(typed)
	case amqp091.Table:
		return tableToMap(typed)
	case map[string]any:
		return tableToMap(typed)
	case []any:
		converted := make([]any, 0, len(typed))

		for _, item := range typed {
			converted = append(converted, fromTableValue(item))
		}

		return converted
	default:
		return typed
	}
}

// timestampToUnix reports a message timestamp in whole seconds, and 0 when the message
// carries none.
func timestampToUnix(moment time.Time) int64 {
	if moment.IsZero() {
		return 0
	}

	return moment.Unix()
}

// unixToTimestamp turns the seconds PHP sent back into a time; 0 means "no timestamp".
func unixToTimestamp(seconds int64) time.Time {
	if seconds == 0 {
		return time.Time{}
	}

	return time.Unix(seconds, 0)
}
