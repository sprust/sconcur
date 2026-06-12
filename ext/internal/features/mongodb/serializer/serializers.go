package serializer

import (
	"errors"
	"fmt"

	"github.com/vmihailenco/msgpack/v5"
	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
)

// Documents are exchanged with the PHP side as raw BSON: the PHP side encodes via
// ext-mongodb (MongoDB\BSON\Document/PackedArray) and decodes via the same C path the
// native driver uses. Go therefore passes the cursor/driver bson.Raw straight through,
// without an intermediate representation.

type WriteModelWrapper struct {
	Type  string             `bson:"type" json:"type" msgpack:"type"`
	Model msgpack.RawMessage `bson:"model" json:"model" msgpack:"model"`
}

// UnmarshalDocument treats the incoming bytes as a raw BSON document. The mongo driver
// accepts bson.Raw directly as a filter/update/projection/document.
func UnmarshalDocument(data []byte) (interface{}, error) {
	return bson.Raw(data), nil
}

// UnmarshalDocuments splits a raw BSON array (PackedArray) into its document elements,
// e.g. for InsertMany.
func UnmarshalDocuments(data []byte) ([]interface{}, error) {
	return bsonArrayDocuments(data)
}

// UnmarshalPipeline splits a raw BSON array into pipeline stage documents.
func UnmarshalPipeline(data []byte) (interface{}, error) {
	return bsonArrayDocuments(data)
}

// MarshalDocument encodes a document to raw BSON bytes. A bson.Raw is passed through
// as-is; anything else (driver result structs, bson.D, ...) is BSON-marshaled.
func MarshalDocument(doc interface{}) (string, error) {
	if raw, ok := doc.(bson.Raw); ok {
		return string(raw), nil
	}

	packed, err := bson.Marshal(doc)

	if err != nil {
		return "", fmt.Errorf("error BSON marshaling: %w", err)
	}

	return string(packed), nil
}

// MarshalDocumentBatchRaw packs a batch as a single raw BSON wrapper document
// {"d": [doc0, doc1, ...]} built directly from the cursor's bson.Raw documents. The PHP
// side decodes it natively via MongoDB\BSON\Document::fromBSON()->toPHP().
func MarshalDocumentBatchRaw(items []bson.Raw) (string, error) {
	arr := make(bson.A, len(items))

	for i, item := range items {
		arr[i] = item
	}

	packed, err := bson.Marshal(bson.M{"d": arr})

	if err != nil {
		return "", fmt.Errorf("error BSON batch marshaling: %w", err)
	}

	return string(packed), nil
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
				return nil, errors.New("deleteOne filter [" + err.Error() + "]")
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

// bsonArrayDocuments reads a raw BSON array and returns its document elements as a slice
// of bson.Raw values (usable directly by the mongo driver).
func bsonArrayDocuments(data []byte) ([]interface{}, error) {
	if len(data) == 0 {
		return []interface{}{}, nil
	}

	values, err := bson.Raw(data).Values()

	if err != nil {
		return nil, fmt.Errorf("error reading BSON array: %w", err)
	}

	documents := make([]interface{}, len(values))

	for i, value := range values {
		// DocumentOK instead of Document: the latter panics on a non-document
		// element, and the input comes straight from PHP user code.
		document, ok := value.DocumentOK()

		if !ok {
			return nil, fmt.Errorf(
				"element %d of BSON array is not a document, got type %s",
				i,
				value.Type.String(),
			)
		}

		documents[i] = document
	}

	return documents, nil
}

func unmarshalMessagePackValue(data msgpack.RawMessage, out interface{}) error {
	return msgpack.Unmarshal(data, out)
}
