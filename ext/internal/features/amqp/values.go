package amqp_feature

import (
	"math"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
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

// tableToMap turns an AMQP field table into values MessagePack can carry: like ext-amqp,
// a timestamp arrives in PHP as whole seconds and a decimal as a float.
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
		return typed.Unix()
	case amqp091.Decimal:
		return decimalToFloat(typed)
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

// decimalToFloat scales a decimal field down by its exponent, the value ext-amqp hands to
// PHP for AMQPDecimal. Dividing once by the power of ten keeps the result exact where
// repeated division by ten would drift (314 with scale 2 is 3.14, not 3.1399999999999997).
func decimalToFloat(decimal amqp091.Decimal) float64 {
	return float64(decimal.Value) / math.Pow(10, float64(decimal.Scale))
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
