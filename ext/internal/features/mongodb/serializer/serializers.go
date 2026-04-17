package serializer

import (
	"errors"
	"fmt"
	"math"
	"time"

	"github.com/vmihailenco/msgpack/v5"
	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/bson/bsontype"
	"go.mongodb.org/mongo-driver/bson/primitive"
	"go.mongodb.org/mongo-driver/mongo"
)

const objectIdStringPrefix = "$oid-ofls:"
const objectIdStringPrefixLen = len(objectIdStringPrefix)

const utcDateTimeStringPrefix = "$udt-lgof:"
const utcDateTimeStringPrefixLen = len(utcDateTimeStringPrefix)
const dateFormat = time.RFC3339Nano

const orderedMapMarker = "\x00m"

type WriteModelWrapper struct {
	Type  string             `bson:"type" json:"type" msgpack:"type"`
	Model msgpack.RawMessage `bson:"model" json:"model" msgpack:"model"`
}

func UnmarshalDocument(data []byte) (interface{}, error) {
	var document interface{}

	if err := msgpack.Unmarshal(data, &document); err != nil {
		return nil, err
	}

	result := unmarshalRecursive(document)

	return normalizeEmptyData(result), nil
}

func UnmarshalDocuments(data []byte) ([]interface{}, error) {
	var documents []interface{}

	if err := msgpack.Unmarshal(data, &documents); err != nil {
		return nil, err
	}

	result := make([]interface{}, len(documents))

	for i, document := range documents {
		result[i] = unmarshalRecursive(document)
	}

	return result, nil
}

func MarshalDocument(doc interface{}) (string, error) {
	packed, err := marshalDocumentBytes(doc)

	if err != nil {
		return "", err
	}

	return string(packed), nil
}

func MarshalDocumentBatch(items []interface{}) (string, error) {
	batch := make([][]byte, len(items))

	for i, item := range items {
		packed, err := marshalDocumentBytes(item)

		if err != nil {
			return "", err
		}

		batch[i] = packed
	}

	packedBatch, err := msgpack.Marshal(batch)

	if err != nil {
		return "", fmt.Errorf("error MessagePack batch marshaling: %w", err)
	}

	return string(packedBatch), nil
}

func MarshalDocumentBatchRaw(items []bson.Raw) (string, error) {
	batch := make([][]byte, len(items))

	for i, item := range items {
		packed, err := marshalDocumentBytes(item)

		if err != nil {
			return "", err
		}

		batch[i] = packed
	}

	packedBatch, err := msgpack.Marshal(batch)

	if err != nil {
		return "", fmt.Errorf("error MessagePack batch marshaling: %w", err)
	}

	return string(packedBatch), nil
}

func marshalDocumentBytes(doc interface{}) ([]byte, error) {
	if raw, ok := doc.(bson.Raw); ok {
		document, err := marshalRawDocument(raw)

		if err != nil {
			return nil, fmt.Errorf("error BSON raw marshaling: %w", err)
		}

		packed, err := msgpack.Marshal(document)

		if err != nil {
			return nil, fmt.Errorf("error MessagePack marshaling: %w", err)
		}

		return packed, nil
	}

	bsonData, err := normalizeToBSON(doc)

	if err != nil {
		return nil, fmt.Errorf("error BSON marshaling: %w", err)
	}

	var document interface{}

	if err := bson.Unmarshal(bsonData, &document); err != nil {
		return nil, fmt.Errorf("error BSON unmarshaling: %w", err)
	}

	packed, err := msgpack.Marshal(marshalRecursive(document))

	if err != nil {
		return nil, fmt.Errorf("error MessagePack marshaling: %w", err)
	}

	return packed, nil
}

func UnmarshalBulkWriteModels(data []byte) ([]mongo.WriteModel, error) {
	var wrappers []WriteModelWrapper

	if err := msgpack.Unmarshal(data, &wrappers); err != nil {
		return nil, err
	}

	models := make([]mongo.WriteModel, 0, len(wrappers))

	for _, wrapper := range wrappers {
		var model mongo.WriteModel

		switch wrapper.Type {
		case "insertOne":
			var im struct {
				Document []byte `msgpack:"document"`
			}

			if err := unmarshalMessagePackValue(wrapper.Model, &im); err != nil {
				return nil, errors.New("insertOne [" + err.Error() + "]")
			}

			document, err := UnmarshalDocument(im.Document)

			if err != nil {
				return nil, errors.New("insertOne document [" + err.Error() + "]")
			}

			model = mongo.NewInsertOneModel().SetDocument(document)
		case "updateOne":
			var um struct {
				Filter []byte `msgpack:"filter"`
				Update []byte `msgpack:"update"`
				Upsert *bool  `msgpack:"upsert,omitempty"`
			}

			if err := unmarshalMessagePackValue(wrapper.Model, &um); err != nil {
				return nil, errors.New("updateOne [" + err.Error() + "]")
			}

			filter, err := UnmarshalDocument(um.Filter)

			if err != nil {
				return nil, errors.New("updateOne filter [" + err.Error() + "]")
			}

			update, err := UnmarshalDocument(um.Update)

			if err != nil {
				return nil, errors.New("updateOne update [" + err.Error() + "]")
			}

			model = mongo.NewUpdateOneModel().
				SetFilter(filter).
				SetUpdate(update)

			if um.Upsert != nil {
				model.(*mongo.UpdateOneModel).SetUpsert(*um.Upsert)
			}
		case "updateMany":
			var um struct {
				Filter []byte `msgpack:"filter"`
				Update []byte `msgpack:"update"`
				Upsert *bool  `msgpack:"upsert,omitempty"`
			}

			if err := unmarshalMessagePackValue(wrapper.Model, &um); err != nil {
				return nil, errors.New("updateMany [" + err.Error() + "]")
			}

			filter, err := UnmarshalDocument(um.Filter)

			if err != nil {
				return nil, errors.New("updateMany filter [" + err.Error() + "]")
			}

			update, err := UnmarshalDocument(um.Update)

			if err != nil {
				return nil, errors.New("updateMany update [" + err.Error() + "]")
			}

			model = mongo.NewUpdateManyModel().
				SetFilter(filter).
				SetUpdate(update)

			if um.Upsert != nil {
				model.(*mongo.UpdateManyModel).SetUpsert(*um.Upsert)
			}
		case "deleteOne":
			var dm struct {
				Filter []byte `msgpack:"filter"`
			}

			if err := unmarshalMessagePackValue(wrapper.Model, &dm); err != nil {
				return nil, errors.New("deleteOne [" + err.Error() + "]")
			}

			filter, err := UnmarshalDocument(dm.Filter)

			if err != nil {
				return nil, errors.New("updateOne filter [" + err.Error() + "]")
			}

			model = mongo.NewDeleteOneModel().SetFilter(filter)
		case "deleteMany":
			var dm struct {
				Filter []byte `msgpack:"filter"`
			}

			if err := unmarshalMessagePackValue(wrapper.Model, &dm); err != nil {
				return nil, errors.New("deleteMany [" + err.Error() + "]")
			}

			filter, err := UnmarshalDocument(dm.Filter)

			if err != nil {
				return nil, errors.New("deleteMany filter [" + err.Error() + "]")
			}

			model = mongo.NewDeleteManyModel().SetFilter(filter)
		case "replaceOne":
			var rm struct {
				Filter      []byte `msgpack:"filter"`
				Replacement []byte `msgpack:"replacement"`
				Upsert      *bool  `msgpack:"upsert,omitempty"`
			}

			if err := unmarshalMessagePackValue(wrapper.Model, &rm); err != nil {
				return nil, errors.New("replaceOne [" + err.Error() + "]")
			}

			filter, err := UnmarshalDocument(rm.Filter)

			if err != nil {
				return nil, errors.New("replaceOne filter [" + err.Error() + "]")
			}

			replacement, err := UnmarshalDocument(rm.Replacement)

			if err != nil {
				return nil, errors.New("replaceOne replacement [" + err.Error() + "]")
			}

			model = mongo.NewReplaceOneModel().
				SetFilter(filter).
				SetReplacement(replacement)

			if rm.Upsert != nil {
				model.(*mongo.ReplaceOneModel).SetUpsert(*rm.Upsert)
			}
		default:
			return nil, fmt.Errorf("unknown type of model: %s", wrapper.Type)
		}

		models = append(models, model)
	}

	return models, nil
}

func normalizeToBSON(doc interface{}) ([]byte, error) {
	if raw, ok := doc.(bson.Raw); ok {
		return []byte(raw), nil
	}

	return bson.Marshal(doc)
}

func unmarshalMessagePackValue(data msgpack.RawMessage, out interface{}) error {
	return msgpack.Unmarshal(data, out)
}

func marshalRawDocument(raw bson.Raw) (interface{}, error) {
	elements, err := raw.Elements()

	if err != nil {
		return nil, err
	}

	items := make([]interface{}, len(elements))

	for i, element := range elements {
		value, err := marshalRawValue(element.Value())

		if err != nil {
			return nil, err
		}

		items[i] = []interface{}{
			element.Key(),
			value,
		}
	}

	return map[string]interface{}{
		orderedMapMarker: items,
	}, nil
}

func marshalRawArray(raw bson.Raw) ([]interface{}, error) {
	values, err := raw.Values()

	if err != nil {
		return nil, err
	}

	result := make([]interface{}, len(values))

	for i, value := range values {
		converted, err := marshalRawValue(value)

		if err != nil {
			return nil, err
		}

		result[i] = converted
	}

	return result, nil
}

func marshalRawValue(value bson.RawValue) (interface{}, error) {
	switch value.Type {
	case bsontype.EmbeddedDocument:
		return marshalRawDocument(value.Document())
	case bsontype.Array:
		return marshalRawArray(value.Array())
	case bsontype.ObjectID:
		return objectIdStringPrefix + value.ObjectID().Hex(), nil
	case bsontype.DateTime:
		return utcDateTimeStringPrefix + value.Time().UTC().Format(dateFormat), nil
	case bsontype.String:
		return value.StringValue(), nil
	case bsontype.Boolean:
		return value.Boolean(), nil
	case bsontype.Int32:
		return int64(value.Int32()), nil
	case bsontype.Int64:
		return value.Int64(), nil
	case bsontype.Double:
		return value.Double(), nil
	case bsontype.Null:
		return nil, nil
	}

	var decoded interface{}

	if err := value.Unmarshal(&decoded); err != nil {
		return nil, err
	}

	return marshalRecursive(decoded), nil
}

func normalizeEmptyData(data interface{}) interface{} {
	if data == nil {
		return bson.D{}
	}

	return data
}

func marshalRecursive(data interface{}) interface{} {
	if data == nil {
		return nil
	}

	switch v := data.(type) {
	case bson.D:
		items := make([]interface{}, len(v))

		for i, elem := range v {
			items[i] = []interface{}{
				elem.Key,
				marshalRecursive(elem.Value),
			}
		}

		return map[string]interface{}{
			orderedMapMarker: items,
		}
	case primitive.A:
		result := make([]interface{}, len(v))

		for i, value := range v {
			result[i] = marshalRecursive(value)
		}

		return result
	case []interface{}:
		result := make([]interface{}, len(v))

		for i, value := range v {
			result[i] = marshalRecursive(value)
		}

		return result
	case map[string]interface{}:
		items := make([]interface{}, len(v))
		i := 0

		for key, value := range v {
			items[i] = []interface{}{
				key,
				marshalRecursive(value),
			}
			i++
		}

		return map[string]interface{}{
			orderedMapMarker: items,
		}
	case primitive.ObjectID:
		return objectIdStringPrefix + v.Hex()
	case primitive.DateTime:
		return utcDateTimeStringPrefix + v.Time().UTC().Format(dateFormat)
	case time.Time:
		return utcDateTimeStringPrefix + v.UTC().Format(dateFormat)
	default:
		return v
	}
}

func unmarshalRecursive(data interface{}) interface{} {
	if data == nil {
		return nil
	}

	switch v := data.(type) {
	case map[string]interface{}:
		if items, ok := v[orderedMapMarker]; ok {
			rawItems, ok := items.([]interface{})

			if !ok {
				return bson.D{}
			}

			result := make(bson.D, 0, len(rawItems))

			for _, rawItem := range rawItems {
				pair, ok := rawItem.([]interface{})

				if !ok || len(pair) != 2 {
					continue
				}

				result = append(result, bson.E{
					Key:   fmt.Sprint(pair[0]),
					Value: unmarshalRecursive(pair[1]),
				})
			}

			return result
		}

		result := make(bson.D, 0, len(v))

		for key, value := range v {
			result = append(result, bson.E{
				Key:   key,
				Value: unmarshalRecursive(value),
			})
		}

		return result
	case []interface{}:
		result := make(primitive.A, len(v))

		for i, value := range v {
			result[i] = unmarshalRecursive(value)
		}

		return result
	case string:
		if len(v) > objectIdStringPrefixLen && v[:objectIdStringPrefixLen] == objectIdStringPrefix {
			if objectID, err := primitive.ObjectIDFromHex(v[objectIdStringPrefixLen:]); err == nil {
				return objectID
			}
		}

		if len(v) > utcDateTimeStringPrefixLen && v[:utcDateTimeStringPrefixLen] == utcDateTimeStringPrefix {
			if t, err := time.Parse(dateFormat, v[utcDateTimeStringPrefixLen:]); err == nil {
				return primitive.NewDateTimeFromTime(t)
			}
		}

		return v
	case float32:
		return float64(v)
	case float64:
		if v == math.Trunc(v) && !math.IsInf(v, 0) && v >= math.MinInt64 && v <= math.MaxInt64 {
			return int64(v)
		}

		return v
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
		if uint64(v) <= math.MaxInt64 {
			return int64(v)
		}

		return uint64(v)
	case uint8:
		return int64(v)
	case uint16:
		return int64(v)
	case uint32:
		return int64(v)
	case uint64:
		if v <= math.MaxInt64 {
			return int64(v)
		}

		return v
	default:
		return v
	}
}
