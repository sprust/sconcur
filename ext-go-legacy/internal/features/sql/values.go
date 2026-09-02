package sql_feature

import (
	"database/sql"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// normalizeBindings converts msgpack-decoded binding values to types the SQL driver
// accepts. msgpack/v5 may decode integers as int8/int16/uint64 etc.; collapse them
// to int64/float64 so database/sql's converter handles them uniformly. Strings,
// bools, []byte and nil pass through.
func normalizeBindings(bindings []any) []any {
	normalized := make([]any, len(bindings))

	for index, value := range bindings {
		normalized[index] = normalizeBinding(value)
	}

	return normalized
}

func normalizeBinding(value any) any {
	switch typed := value.(type) {
	case int8:
		return int64(typed)
	case int16:
		return int64(typed)
	case int32:
		return int64(typed)
	case int:
		return int64(typed)
	case uint8:
		return int64(typed)
	case uint16:
		return int64(typed)
	case uint32:
		return int64(typed)
	case uint:
		return int64(typed)
	case uint64:
		return int64(typed)
	case float32:
		return float64(typed)
	default:
		return value
	}
}

// scanRow reads the current row into a column-keyed map, normalizing the driver's
// raw values (see normalizeColumnValue).
func scanRow(rows *sql.Rows, columns []string) (map[string]any, error) {
	values := make([]any, len(columns))
	pointers := make([]any, len(columns))

	for index := range values {
		pointers[index] = &values[index]
	}

	if err := rows.Scan(pointers...); err != nil {
		return nil, err
	}

	row := make(map[string]any, len(columns))

	for index, column := range columns {
		row[column] = normalizeColumnValue(values[index])
	}

	return row, nil
}

// normalizeColumnValue makes a scanned value MessagePack-friendly: drivers return
// many SQL types as []byte (rendered as a string) and dates as time.Time (rendered
// RFC3339). Integers, floats, bools and nil pass through.
func normalizeColumnValue(value any) any {
	switch typed := value.(type) {
	case []byte:
		return string(typed)
	case time.Time:
		return typed.Format(time.RFC3339Nano)
	default:
		return value
	}
}

// marshalBatch encodes a batch of rows as a MessagePack array. A nil/empty batch
// encodes as an empty array, never null, so the PHP side always decodes to a list.
func marshalBatch(items []map[string]any) (string, error) {
	if items == nil {
		items = []map[string]any{}
	}

	encoded, err := msgpack.Marshal(items)

	if err != nil {
		return "", err
	}

	return string(encoded), nil
}
