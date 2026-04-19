package objects

type Payload struct {
	Url             string `json:"ul" msgpack:"ul"`
	Database        string `json:"db" msgpack:"db"`
	Collection      string `json:"cl" msgpack:"cl"`
	SocketTimeoutMs int    `json:"sto" msgpack:"sto"`
	Command         int    `json:"cm" msgpack:"cm"`
	Data            []byte `json:"dt" msgpack:"dt"`
}

type UpdateParams struct {
	Filter []byte `json:"f" msgpack:"f"`
	Update []byte `json:"u" msgpack:"u"`
	Upsert bool   `json:"ou" msgpack:"ou"`
}

type FindOneParams struct {
	Filter     []byte `json:"f" msgpack:"f"`
	Projection []byte `json:"op" msgpack:"op"`
}

type FindParams struct {
	Filter     []byte `json:"f" msgpack:"f"`
	Projection []byte `json:"op" msgpack:"op"`
	Sort       []byte `json:"s" msgpack:"s"`
	Limit      int64  `json:"l" msgpack:"l"`
	Skip       int64  `json:"sk" msgpack:"sk"`
	BatchSize  int    `json:"bs" msgpack:"bs"`
}

type AggregateParams struct {
	Pipeline  []byte `json:"p" msgpack:"p"`
	BatchSize int    `json:"bs" msgpack:"bs"`
}

type CreateIndexParams struct {
	Keys []byte `json:"k" msgpack:"k"`
	Name string `json:"n" msgpack:"n"`
}

type CreateIndexesParams struct {
	Indexes []CreateIndexParams `json:"ix" msgpack:"ix"`
}

type DropIndexParams struct {
	Name string `json:"n" msgpack:"n"`
}

type DeleteOneParams struct {
	Filter []byte `json:"f" msgpack:"f"`
}

type DeleteManyParams struct {
	Filter []byte `json:"f" msgpack:"f"`
}

type DistinctParams struct {
	FieldName string `json:"fn" msgpack:"fn"`
	Filter    []byte `json:"f" msgpack:"f"`
}

type FindOneAndUpdateParams struct {
	Filter         []byte `json:"f" msgpack:"f"`
	Update         []byte `json:"u" msgpack:"u"`
	Projection     []byte `json:"op" msgpack:"op"`
	Upsert         bool   `json:"ou" msgpack:"ou"`
	ReturnDocument bool   `json:"rd" msgpack:"rd"`
}

type FindOneAndDeleteParams struct {
	Filter     []byte `json:"f" msgpack:"f"`
	Projection []byte `json:"op" msgpack:"op"`
}

type FindOneAndReplaceParams struct {
	Filter         []byte `json:"f" msgpack:"f"`
	Replacement    []byte `json:"r" msgpack:"r"`
	Projection     []byte `json:"op" msgpack:"op"`
	Upsert         bool   `json:"ou" msgpack:"ou"`
	ReturnDocument bool   `json:"rd" msgpack:"rd"`
}

type ReplaceOneParams struct {
	Filter      []byte `json:"f" msgpack:"f"`
	Replacement []byte `json:"r" msgpack:"r"`
	Upsert      bool   `json:"ou" msgpack:"ou"`
}

type RenameCollectionParams struct {
	Target     string `json:"t" msgpack:"t"`
	DropTarget bool   `json:"dt" msgpack:"dt"`
}
