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

type AggregateParams struct {
	Pipeline  []byte `json:"p" msgpack:"p"`
	BatchSize int    `json:"bs" msgpack:"bs"`
}

type CreateIndexParams struct {
	Keys []byte `json:"k" msgpack:"k"`
	Name string `json:"n" msgpack:"n"`
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
