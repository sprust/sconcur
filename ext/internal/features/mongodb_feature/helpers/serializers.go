package helpers

import (
	"encoding/json"
	"errors"
	"fmt"
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

	err := json.Unmarshal([]byte(data), &document)

	if err != nil {
		return nil, err
	}

	result := unmarshalRecursive(document)

	return normalizeEmptyData(result), nil
}

func UnmarshalDocuments(data string) ([]interface{}, error) {
	var documents []interface{}

	err := json.Unmarshal([]byte(data), &documents)

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
				Document interface{} `json:"document"`
			}
			if err := json.Unmarshal(wrapper.Model, &im); err != nil {
				return nil, errors.New("insertOne [" + err.Error() + "]")
			}
			model = mongo.NewInsertOneModel().SetDocument(unmarshalRecursive(im.Document))
		case "updateOne":
			var um struct {
				Filter interface{} `json:"filter"`
				Update interface{} `json:"update"`
				Upsert *bool       `json:"upsert,omitempty"`
			}
			if err := json.Unmarshal(wrapper.Model, &um); err != nil {
				return nil, errors.New("updateOne [" + err.Error() + "]")
			}
			um.Filter = normalizeEmptyData(um.Filter)
			model = mongo.NewUpdateOneModel().
				SetFilter(unmarshalRecursive(um.Filter)).
				SetUpdate(unmarshalRecursive(um.Update))
			if um.Upsert != nil {
				model.(*mongo.UpdateOneModel).SetUpsert(*um.Upsert)
			}

		case "updateMany":
			var um struct {
				Filter interface{} `json:"filter"`
				Update interface{} `json:"update"`
				Upsert *bool       `json:"upsert,omitempty"`
			}
			if err := json.Unmarshal(wrapper.Model, &um); err != nil {
				return nil, errors.New("updateMany [" + err.Error() + "]")
			}
			um.Filter = normalizeEmptyData(um.Filter)
			model = mongo.NewUpdateManyModel().
				SetFilter(unmarshalRecursive(um.Filter)).
				SetUpdate(unmarshalRecursive(um.Update))
			if um.Upsert != nil {
				model.(*mongo.UpdateManyModel).SetUpsert(*um.Upsert)
			}

		case "deleteOne":
			var dm struct {
				Filter interface{} `json:"filter"`
			}
			if err := json.Unmarshal(wrapper.Model, &dm); err != nil {
				return nil, errors.New("deleteOne [" + err.Error() + "]")
			}
			dm.Filter = normalizeEmptyData(dm.Filter)
			model = mongo.NewDeleteOneModel().SetFilter(unmarshalRecursive(dm.Filter))

		case "deleteMany":
			var dm struct {
				Filter interface{} `json:"filter"`
			}
			if err := json.Unmarshal(wrapper.Model, &dm); err != nil {
				return nil, errors.New("deleteMany [" + err.Error() + "]")
			}
			dm.Filter = normalizeEmptyData(dm.Filter)
			model = mongo.NewDeleteManyModel().SetFilter(unmarshalRecursive(dm.Filter))

		case "replaceOne":
			var rm struct {
				Filter      interface{} `json:"filter"`
				Replacement interface{} `json:"replacement"`
				Upsert      *bool       `json:"upsert,omitempty"`
			}
			if err := json.Unmarshal(wrapper.Model, &rm); err != nil {
				return nil, errors.New("replaceOne [" + err.Error() + "]")
			}
			rm.Filter = normalizeEmptyData(rm.Filter)
			model = mongo.NewReplaceOneModel().
				SetFilter(unmarshalRecursive(rm.Filter)).
				SetReplacement(unmarshalRecursive(rm.Replacement))
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
		return v
	}
}
