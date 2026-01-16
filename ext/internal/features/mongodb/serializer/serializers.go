package serializer

import (
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"time"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/bson/primitive"
	"go.mongodb.org/mongo-driver/mongo"
)

const objectIdStringPrefix = "$oid-ofls:"
const objectIdStringPrefixLen = len(objectIdStringPrefix)

const utcDateTimeStringPrefix = "$udt-lgof:"
const utcDateTimeStringPrefixLen = len(utcDateTimeStringPrefix)
const dateFormat = time.RFC3339Nano

type WriteModelWrapper struct {
	Type  string          `json:"type"`
	Model json.RawMessage `json:"model"`
}

func UnmarshalDocument(data string) (interface{}, error) {
	var document interface{}

	err := bson.UnmarshalExtJSON([]byte(data), true, &document)

	if err != nil {
		return nil, err
	}

	result := unmarshalRecursive(document)

	return normalizeEmptyData(result), nil
}

func UnmarshalDocuments(data string) ([]interface{}, error) {
	var documents []interface{}

	err := bson.UnmarshalExtJSON([]byte(data), true, &documents)

	if err != nil {
		return nil, err
	}

	result := make([]interface{}, len(documents))

	for i, document := range documents {
		result[i] = unmarshalRecursive(document)
	}

	return result, nil
}

func MarshalDocument(doc interface{}) (string, error) {
	bsonData, err := bson.Marshal(doc)

	if err != nil {
		return "", fmt.Errorf("error BSON marshaling: %w", err)
	}

	var raw bson.Raw = bsonData

	jsonData, err := bson.MarshalExtJSON(raw, true, true)

	if err != nil {
		return "", fmt.Errorf("converting error in Extended Json: %w", err)
	}

	return string(jsonData), nil
}

func UnmarshalBulkWriteModels(data string) ([]mongo.WriteModel, error) {
	var wrappers []WriteModelWrapper

	if err := json.Unmarshal([]byte(data), &wrappers); err != nil {
		return nil, err
	}

	models := make([]mongo.WriteModel, 0, len(wrappers))

	for _, wrapper := range wrappers {
		var model mongo.WriteModel

		switch wrapper.Type {
		case "insertOne":
			var im struct {
				Document string `json:"document"`
			}

			if err := json.Unmarshal(wrapper.Model, &im); err != nil {
				return nil, errors.New("insertOne [" + err.Error() + "]")
			}

			document, err := UnmarshalDocument(im.Document)

			if err != nil {
				return nil, errors.New("insertOne document [" + err.Error() + "]")
			}

			model = mongo.NewInsertOneModel().SetDocument(document)
		case "updateOne":
			var um struct {
				Filter string `json:"filter"`
				Update string `json:"update"`
				Upsert *bool  `json:"upsert,omitempty"`
			}

			if err := json.Unmarshal(wrapper.Model, &um); err != nil {
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
				Filter string `json:"filter"`
				Update string `json:"update"`
				Upsert *bool  `json:"upsert,omitempty"`
			}

			if err := json.Unmarshal(wrapper.Model, &um); err != nil {
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
				Filter string `json:"filter"`
			}

			if err := json.Unmarshal(wrapper.Model, &dm); err != nil {
				return nil, errors.New("deleteOne [" + err.Error() + "]")
			}

			filter, err := UnmarshalDocument(dm.Filter)

			if err != nil {
				return nil, errors.New("updateOne filter [" + err.Error() + "]")
			}

			model = mongo.NewDeleteOneModel().SetFilter(filter)
		case "deleteMany":
			var dm struct {
				Filter string `json:"filter"`
			}

			if err := json.Unmarshal(wrapper.Model, &dm); err != nil {
				return nil, errors.New("deleteMany [" + err.Error() + "]")
			}

			filter, err := UnmarshalDocument(dm.Filter)

			if err != nil {
				return nil, errors.New("deleteMany filter [" + err.Error() + "]")
			}

			model = mongo.NewDeleteManyModel().SetFilter(filter)
		case "replaceOne":
			var rm struct {
				Filter      string `json:"filter"`
				Replacement string `json:"replacement"`
				Upsert      *bool  `json:"upsert,omitempty"`
			}

			if err := json.Unmarshal(wrapper.Model, &rm); err != nil {
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

func normalizeEmptyData(data interface{}) interface{} {
	if data == nil {
		return bson.D{}
	}

	if slice, ok := data.([]interface{}); ok && len(slice) == 0 {
		return bson.D{}
	}

	return data
}

func unmarshalRecursive(data interface{}) interface{} {
	if data == nil {
		return nil
	}

	switch v := data.(type) {
	case bson.D:
		result := make(bson.D, len(v))

		for i, elem := range v {
			result[i] = bson.E{
				Key:   elem.Key,
				Value: unmarshalRecursive(elem.Value),
			}
		}

		return result
	case primitive.A:
		result := make(primitive.A, len(v))

		for i, value := range v {
			result[i] = unmarshalRecursive(value)
		}

		return result
	case map[string]interface{}:
		result := make(map[string]interface{})

		for key, value := range v {
			result[key] = unmarshalRecursive(value)
		}

		return result

	case []interface{}:
		result := make([]interface{}, len(v))

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
	default:
		if f, ok := v.(float64); ok {
			if f == math.Trunc(f) && !math.IsInf(f, 0) {
				if f >= math.MinInt64 && f <= math.MaxInt64 {
					return int64(f)
				}
			}
		}

		return v
	}
}
