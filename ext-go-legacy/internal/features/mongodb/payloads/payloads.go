// Package payloads holds the Go counterparts of the PHP MongoDB payload objects
// (SConcur\Features\Mongodb\Payloads\*). Each PHP *Payload class produces, via its
// getData() method, the representation that travels to this extension:
//
//   - the envelope (ul/db/cl/to/sst/cm/dt) — built by Base\BaseMongodbPayload from
//     Dto\Connection — maps to Payload below;
//   - the per-command parameters, serialized into the envelope's `dt` field as a BSON
//     document, map to the per-command structs below. Their names mirror the PHP
//     *Payload classes 1:1; the PHP-side *PayloadParameters classes are a PHP-only
//     convenience for assembling `dt` and have no Go counterpart — their fields are
//     inlined here (options fields hn/co/af included).
//
// The struct tags are the short keys emitted by the PHP getData() methods.
package payloads

import "sconcur/internal/types"

// Payload is the command envelope decoded from the msgpack message.
// PHP: SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload (ul/db/cl/to/sst from
// Dto\Connection; cm from the command enum; dt from the parameters' getData()).
type Payload struct {
	Url                      string               `json:"ul" msgpack:"ul"`
	Database                 string               `json:"db" msgpack:"db"`
	Collection               string               `json:"cl" msgpack:"cl"`
	TimeoutMs                int                  `json:"to" msgpack:"to"`
	ServerSelectionTimeoutMs int                  `json:"sst" msgpack:"sst"`
	Command                  types.MongodbCommand `json:"cm" msgpack:"cm"`
	Data                     []byte               `json:"dt" msgpack:"dt"`
}

// AggregatePayload is the `dt` content of an aggregate command.
// PHP: SConcur\Features\Mongodb\Payloads\AggregatePayload (AggregatePayloadParameters).
type AggregatePayload struct {
	Pipeline  []byte `json:"p" msgpack:"p"`
	BatchSize int    `json:"bs" msgpack:"bs"`
}

// UpdateOnePayload is the `dt` content of an updateOne command.
// PHP: SConcur\Features\Mongodb\Payloads\UpdateOnePayload (UpdateOnePayloadParameters;
// hn/co/af come from OptionsPayloadParameters).
type UpdateOnePayload struct {
	Filter       []byte `json:"f" msgpack:"f"`
	Update       []byte `json:"u" msgpack:"u"`
	Upsert       bool   `json:"ou" msgpack:"ou"`
	Hint         []byte `json:"hn" msgpack:"hn"`
	Collation    []byte `json:"co" msgpack:"co"`
	ArrayFilters []byte `json:"af" msgpack:"af"`
}

// UpdateManyPayload is the `dt` content of an updateMany command; identical shape to
// UpdateOnePayload.
// PHP: SConcur\Features\Mongodb\Payloads\UpdateManyPayload (extends UpdateOnePayload).
type UpdateManyPayload = UpdateOnePayload

// FindOnePayload is the `dt` content of a findOne command.
// PHP: SConcur\Features\Mongodb\Payloads\FindOnePayload (FindOnePayloadParameters;
// hn/co from OptionsPayloadParameters).
type FindOnePayload struct {
	Filter     []byte `json:"f" msgpack:"f"`
	Projection []byte `json:"op" msgpack:"op"`
	Hint       []byte `json:"hn" msgpack:"hn"`
	Collation  []byte `json:"co" msgpack:"co"`
}

// FindPayload is the `dt` content of a find command.
// PHP: SConcur\Features\Mongodb\Payloads\FindPayload (FindPayloadParameters;
// hn/co from OptionsPayloadParameters).
type FindPayload struct {
	Filter     []byte `json:"f" msgpack:"f"`
	Projection []byte `json:"op" msgpack:"op"`
	Sort       []byte `json:"s" msgpack:"s"`
	Limit      int64  `json:"l" msgpack:"l"`
	Skip       int64  `json:"sk" msgpack:"sk"`
	BatchSize  int    `json:"bs" msgpack:"bs"`
	Hint       []byte `json:"hn" msgpack:"hn"`
	Collation  []byte `json:"co" msgpack:"co"`
}

// CreateIndexPayload is the `dt` content of a createIndex command.
// PHP: SConcur\Features\Mongodb\Payloads\CreateIndexPayload (CreateIndexPayloadParameters).
type CreateIndexPayload struct {
	Keys []byte `json:"k" msgpack:"k"`
	Name string `json:"n" msgpack:"n"`
}

// DropIndexPayload is the `dt` content of a dropIndex command.
// PHP: SConcur\Features\Mongodb\Payloads\DropIndexPayload (DropIndexPayloadParameters).
type DropIndexPayload struct {
	Name string `json:"n" msgpack:"n"`
}

// DeleteOnePayload is the `dt` content of a deleteOne command.
// PHP: SConcur\Features\Mongodb\Payloads\DeleteOnePayload (DeleteOnePayloadParameters;
// hn/co from OptionsPayloadParameters).
type DeleteOnePayload struct {
	Filter    []byte `json:"f" msgpack:"f"`
	Hint      []byte `json:"hn" msgpack:"hn"`
	Collation []byte `json:"co" msgpack:"co"`
}

// DeleteManyPayload is the `dt` content of a deleteMany command; identical shape to
// DeleteOnePayload.
// PHP: SConcur\Features\Mongodb\Payloads\DeleteManyPayload (extends DeleteOnePayload).
type DeleteManyPayload = DeleteOnePayload

// DistinctPayload is the `dt` content of a distinct command.
// PHP: SConcur\Features\Mongodb\Payloads\DistinctPayload (DistinctPayloadParameters;
// co from OptionsPayloadParameters).
type DistinctPayload struct {
	FieldName string `json:"fn" msgpack:"fn"`
	Filter    []byte `json:"f" msgpack:"f"`
	Collation []byte `json:"co" msgpack:"co"`
}

// FindOneAndUpdatePayload is the `dt` content of a findOneAndUpdate command.
// PHP: SConcur\Features\Mongodb\Payloads\FindOneAndUpdatePayload
// (FindOneAndUpdatePayloadParameters; hn/co/af from OptionsPayloadParameters).
type FindOneAndUpdatePayload struct {
	Filter         []byte `json:"f" msgpack:"f"`
	Update         []byte `json:"u" msgpack:"u"`
	Projection     []byte `json:"op" msgpack:"op"`
	Upsert         bool   `json:"ou" msgpack:"ou"`
	ReturnDocument bool   `json:"rd" msgpack:"rd"`
	Hint           []byte `json:"hn" msgpack:"hn"`
	Collation      []byte `json:"co" msgpack:"co"`
	ArrayFilters   []byte `json:"af" msgpack:"af"`
}

// FindOneAndDeletePayload is the `dt` content of a findOneAndDelete command.
// PHP: SConcur\Features\Mongodb\Payloads\FindOneAndDeletePayload
// (FindOneAndDeletePayloadParameters).
type FindOneAndDeletePayload struct {
	Filter     []byte `json:"f" msgpack:"f"`
	Projection []byte `json:"op" msgpack:"op"`
}

// FindOneAndReplacePayload is the `dt` content of a findOneAndReplace command.
// PHP: SConcur\Features\Mongodb\Payloads\FindOneAndReplacePayload
// (FindOneAndReplacePayloadParameters).
type FindOneAndReplacePayload struct {
	Filter         []byte `json:"f" msgpack:"f"`
	Replacement    []byte `json:"r" msgpack:"r"`
	Projection     []byte `json:"op" msgpack:"op"`
	Upsert         bool   `json:"ou" msgpack:"ou"`
	ReturnDocument bool   `json:"rd" msgpack:"rd"`
}

// ReplaceOnePayload is the `dt` content of a replaceOne command.
// PHP: SConcur\Features\Mongodb\Payloads\ReplaceOnePayload (ReplaceOnePayloadParameters).
type ReplaceOnePayload struct {
	Filter      []byte `json:"f" msgpack:"f"`
	Replacement []byte `json:"r" msgpack:"r"`
	Upsert      bool   `json:"ou" msgpack:"ou"`
}

// RenameCollectionPayload is the `dt` content of a renameCollection command.
// PHP: SConcur\Features\Mongodb\Payloads\RenameCollectionPayload
// (RenameCollectionPayloadParameters).
type RenameCollectionPayload struct {
	Target     string `json:"t" msgpack:"t"`
	DropTarget bool   `json:"dt" msgpack:"dt"`
}

// Commands whose PHP *Payload carries no named `dt` fields have no struct here; their
// `dt` is consumed directly by the handler as raw BSON (or is empty):
//
//   - InsertOnePayload          — dt is the document itself (raw BSON document)
//   - InsertManyPayload         — dt is the documents array (raw BSON array)
//   - CountDocumentsPayload     — dt is the filter (raw BSON document)
//   - RunCommandPayload         — dt is the command (raw BSON document)
//   - BulkWritePayload          — dt is the operations array (see serializer.UnmarshalBulkWriteModels)
//   - CreateIndexesPayload      — dt is {ix: [{k, n}, ...]} (read inline in Collection.CreateIndexes)
//   - DropPayload               — dt is empty (EmptyPayloadParameters)
//   - EstimatedDocumentCountPayload, ListCollectionsPayload, ListDatabasesPayload,
//     ListIndexesPayload        — dt is empty (EmptyPayloadParameters)
