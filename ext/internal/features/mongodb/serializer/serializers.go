package serializer

import (
	"errors"
	"fmt"

	"go.mongodb.org/mongo-driver/v2/bson"
	"go.mongodb.org/mongo-driver/v2/mongo"
)

// Documents are exchanged with the PHP side as raw BSON: the PHP side encodes via
// ext-mongodb (MongoDB\BSON\Document/PackedArray) and decodes via the same C path the
// native driver uses. Go therefore passes the cursor/driver bson.Raw straight through,
// without an intermediate representation.

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

// UnmarshalBulkWriteModels reads the bulkWrite payload as a single raw BSON document
// produced by the PHP side: an ordered map {"0": {type, model}, "1": {...}, ...}. Each
// operation's nested filter/update/document/replacement are themselves BSON sub-documents
// passed straight to the driver as bson.Raw.
func UnmarshalBulkWriteModels(data []byte) ([]mongo.WriteModel, error) {
	if len(data) == 0 {
		return []mongo.WriteModel{}, nil
	}

	elements, err := bson.Raw(data).Elements()

	if err != nil {
		return nil, fmt.Errorf("error reading bulkWrite BSON: %w", err)
	}

	models := make([]mongo.WriteModel, 0, len(elements))

	for _, element := range elements {
		wrapper, ok := element.Value().DocumentOK()

		if !ok {
			return nil, fmt.Errorf("bulkWrite operation %q is not a document", element.Key())
		}

		operationType, ok := wrapper.Lookup("type").StringValueOK()

		if !ok {
			return nil, fmt.Errorf("bulkWrite operation %q has no string type", element.Key())
		}

		model, ok := wrapper.Lookup("model").DocumentOK()

		if !ok {
			return nil, errors.New(operationType + " [model is not a document]")
		}

		writeModel, err := buildBulkWriteModel(operationType, model)

		if err != nil {
			return nil, err
		}

		models = append(models, writeModel)
	}

	return models, nil
}

// buildBulkWriteModel maps a single operation type and its model document to the driver's
// write model. Document fields are passed as bson.Raw; the optional upsert flag is read
// from the model when present.
func buildBulkWriteModel(operationType string, model bson.Raw) (mongo.WriteModel, error) {
	switch operationType {
	case "insertOne":
		document, err := bulkWriteDocumentField(model, "document")

		if err != nil {
			return nil, errors.New("insertOne document [" + err.Error() + "]")
		}

		return mongo.NewInsertOneModel().SetDocument(document), nil
	case "updateOne", "updateMany":
		filter, err := bulkWriteDocumentField(model, "filter")

		if err != nil {
			return nil, errors.New(operationType + " filter [" + err.Error() + "]")
		}

		update, err := bulkWriteDocumentField(model, "update")

		if err != nil {
			return nil, errors.New(operationType + " update [" + err.Error() + "]")
		}

		if operationType == "updateOne" {
			updateModel := mongo.NewUpdateOneModel().SetFilter(filter).SetUpdate(update)

			if upsert, ok := bulkWriteUpsert(model); ok {
				updateModel.SetUpsert(upsert)
			}

			return updateModel, nil
		}

		updateModel := mongo.NewUpdateManyModel().SetFilter(filter).SetUpdate(update)

		if upsert, ok := bulkWriteUpsert(model); ok {
			updateModel.SetUpsert(upsert)
		}

		return updateModel, nil
	case "deleteOne":
		filter, err := bulkWriteDocumentField(model, "filter")

		if err != nil {
			return nil, errors.New("deleteOne filter [" + err.Error() + "]")
		}

		return mongo.NewDeleteOneModel().SetFilter(filter), nil
	case "deleteMany":
		filter, err := bulkWriteDocumentField(model, "filter")

		if err != nil {
			return nil, errors.New("deleteMany filter [" + err.Error() + "]")
		}

		return mongo.NewDeleteManyModel().SetFilter(filter), nil
	case "replaceOne":
		filter, err := bulkWriteDocumentField(model, "filter")

		if err != nil {
			return nil, errors.New("replaceOne filter [" + err.Error() + "]")
		}

		replacement, err := bulkWriteDocumentField(model, "replacement")

		if err != nil {
			return nil, errors.New("replaceOne replacement [" + err.Error() + "]")
		}

		replaceModel := mongo.NewReplaceOneModel().SetFilter(filter).SetReplacement(replacement)

		if upsert, ok := bulkWriteUpsert(model); ok {
			replaceModel.SetUpsert(upsert)
		}

		return replaceModel, nil
	default:
		return nil, fmt.Errorf("unknown type of model: %s", operationType)
	}
}

// bulkWriteDocumentField extracts a nested BSON sub-document from a model by key. An empty
// filter/update/document is encoded by the PHP side as an empty BSON array; its bytes form
// a valid empty document, so arrays are accepted as well.
func bulkWriteDocumentField(model bson.Raw, key string) (bson.Raw, error) {
	value := model.Lookup(key)

	switch value.Type {
	case bson.TypeEmbeddedDocument:
		return value.Document(), nil
	case bson.TypeArray:
		return bson.Raw(value.Value), nil
	default:
		return nil, fmt.Errorf("%s is missing or not a document", key)
	}
}

// bulkWriteUpsert reads the optional boolean upsert flag from a model document.
func bulkWriteUpsert(model bson.Raw) (bool, bool) {
	return model.Lookup("upsert").BooleanOK()
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
