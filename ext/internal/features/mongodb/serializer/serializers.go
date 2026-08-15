package serializer

import (
	"errors"
	"fmt"

	"go.mongodb.org/mongo-driver/v2/bson"
	"go.mongodb.org/mongo-driver/v2/mongo"
)

// Documents are exchanged with the PHP side as MessagePack, so PHP needs no BSON
// codec and SConcur does not depend on ext-mongodb. See msgpack.go for the format.
//
// The conversion happens exactly once per message, at the outer boundary: either
// payloads.UnmarshalParams (which decodes the whole `dt` blob) or the Payload*
// helpers below, for commands whose `dt` is the document itself. Everything
// inside — a filter, an update, a pipeline stage — is then already BSON, and the
// functions that read those inner fields keep working on BSON unchanged.

// PayloadDocument converts an incoming MessagePack payload into raw BSON. Use it
// where the command's `dt` is the document itself.
func PayloadDocument(data []byte) (interface{}, error) {
	return MsgpackToBSON(data)
}

// PayloadDocuments converts an incoming MessagePack payload that holds a list of
// documents (insertMany).
func PayloadDocuments(data []byte) ([]interface{}, error) {
	return MsgpackToBSONDocuments(data)
}

// UnmarshalDocument treats already-converted bytes as a raw BSON document. The
// mongo driver accepts bson.Raw directly as a filter/update/projection/document.
func UnmarshalDocument(data []byte) (interface{}, error) {
	return bson.Raw(data), nil
}

// UnmarshalDocuments splits a raw BSON array into its document elements.
func UnmarshalDocuments(data []byte) ([]interface{}, error) {
	return bsonArrayDocuments(data)
}

// UnmarshalPipeline splits a raw BSON array into pipeline stage documents.
func UnmarshalPipeline(data []byte) (interface{}, error) {
	return bsonArrayDocuments(data)
}

// MarshalDocument encodes a document as MessagePack for PHP. A bson.Raw is
// converted directly; anything else (driver result structs, bson.D, ...) is
// BSON-marshaled first, since the driver's own encoder is the only thing that
// knows those shapes.
func MarshalDocument(doc interface{}) (string, error) {
	raw, ok := doc.(bson.Raw)

	if !ok {
		packed, err := bson.Marshal(doc)

		if err != nil {
			return "", fmt.Errorf("error BSON marshaling: %w", err)
		}

		raw = packed
	}

	encoded, err := BSONToMsgpack(raw)

	if err != nil {
		return "", err
	}

	return string(encoded), nil
}

// MarshalDocumentBatchRaw packs a cursor batch as a MessagePack list. The BSON path
// needed a {"d": [...]} wrapper because PHP could only decode a document; a list
// needs no wrapper.
func MarshalDocumentBatchRaw(items []bson.Raw) (string, error) {
	encoded, err := BSONBatchToMsgpack(items)

	if err != nil {
		return "", err
	}

	return string(encoded), nil
}

// UnmarshalBulkWriteModels reads the bulkWrite payload as a single raw BSON document
// produced by the PHP side: an ordered map {"0": {type, model}, "1": {...}, ...}. Each
// operation's nested filter/update/document/replacement are themselves BSON sub-documents
// passed straight to the driver as bson.Raw.
func UnmarshalBulkWriteModels(data []byte) ([]mongo.WriteModel, error) {
	if len(data) == 0 {
		return []mongo.WriteModel{}, nil
	}

	raw, err := MsgpackToBSON(data)

	if err != nil {
		return nil, fmt.Errorf("error reading bulkWrite payload: %w", err)
	}

	elements, err := raw.Elements()

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

// bsonArrayDocuments reads a raw BSON array and returns its document elements as a
// slice of bson.Raw values (usable directly by the mongo driver).
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
