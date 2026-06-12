package objects

import (
	"fmt"
	"reflect"
	"strings"

	"go.mongodb.org/mongo-driver/v2/bson"
)

// UnmarshalParams decodes command parameters from the single BSON document carried in the
// payload's `dt` field. Each struct field is matched by its msgpack tag to a key in the
// document: []byte fields receive the raw bytes of the embedded document/array (usable as
// bson.Raw), scalar fields receive the decoded value. Absent keys keep their zero value.
func UnmarshalParams(data []byte, out interface{}) error {
	if len(data) == 0 {
		return nil
	}

	pointer := reflect.ValueOf(out)

	if pointer.Kind() != reflect.Ptr || pointer.Elem().Kind() != reflect.Struct {
		return fmt.Errorf("out must be a pointer to a struct")
	}

	document := bson.Raw(data)
	structValue := pointer.Elem()
	structType := structValue.Type()

	for i := 0; i < structType.NumField(); i++ {
		key := paramKey(structType.Field(i))

		if key == "" || key == "-" {
			continue
		}

		value, err := document.LookupErr(key)

		if err != nil {
			// Absent key: leave the field at its zero value.
			continue
		}

		if err := assignParamField(structValue.Field(i), value); err != nil {
			return fmt.Errorf("field %q: %w", key, err)
		}
	}

	return nil
}

func paramKey(field reflect.StructField) string {
	tag := field.Tag.Get("msgpack")

	if tag == "" {
		return ""
	}

	if index := strings.IndexByte(tag, ','); index >= 0 {
		tag = tag[:index]
	}

	return tag
}

func assignParamField(target reflect.Value, value bson.RawValue) error {
	switch target.Kind() {
	case reflect.Slice:
		if target.Type().Elem().Kind() != reflect.Uint8 {
			return fmt.Errorf("unsupported slice element %s", target.Type().Elem().Kind())
		}

		raw := make([]byte, len(value.Value))
		copy(raw, value.Value)
		target.SetBytes(raw)
	case reflect.Bool:
		boolean, ok := value.BooleanOK()

		if !ok {
			return fmt.Errorf("expected boolean, got %s", value.Type)
		}

		target.SetBool(boolean)
	case reflect.String:
		text, ok := value.StringValueOK()

		if !ok {
			return fmt.Errorf("expected string, got %s", value.Type)
		}

		target.SetString(text)
	case reflect.Int, reflect.Int32, reflect.Int64:
		number, ok := paramInt64(value)

		if !ok {
			return fmt.Errorf("expected integer, got %s", value.Type)
		}

		target.SetInt(number)
	default:
		return fmt.Errorf("unsupported field kind %s", target.Kind())
	}

	return nil
}

func paramInt64(value bson.RawValue) (int64, bool) {
	switch value.Type {
	case bson.TypeInt32:
		number, ok := value.Int32OK()
		return int64(number), ok
	case bson.TypeInt64:
		return value.Int64OK()
	case bson.TypeDouble:
		number, ok := value.DoubleOK()
		return int64(number), ok
	default:
		return 0, false
	}
}
