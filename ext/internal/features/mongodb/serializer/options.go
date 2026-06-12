package serializer

import (
	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo/options"
)

// ParseHint decodes a hint sent as a {"v": <string|document>} wrapper. A string is an
// index name; a document is an index key spec. Returns nil when no hint was provided.
func ParseHint(data []byte) interface{} {
	if len(data) == 0 {
		return nil
	}

	value := bson.Raw(data).Lookup("v")

	switch value.Type {
	case bson.TypeString:
		return value.StringValue()
	case bson.TypeEmbeddedDocument:
		return value.Document()
	default:
		return nil
	}
}

// ParseCollation decodes a collation specification document into driver options.
// Returns nil when no collation was provided.
func ParseCollation(data []byte) (*options.Collation, error) {
	if len(data) == 0 {
		return nil, nil
	}

	var collation options.Collation

	if err := bson.Unmarshal(data, &collation); err != nil {
		return nil, err
	}

	return &collation, nil
}

// ParseArrayFilters decodes a raw BSON array of filter documents (used by array update
// operators with the $[<identifier>] syntax). Returns nil when none were provided.
func ParseArrayFilters(data []byte) ([]interface{}, error) {
	if len(data) == 0 {
		return nil, nil
	}

	return bsonArrayDocuments(data)
}
