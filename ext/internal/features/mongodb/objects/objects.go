package objects

type Payload struct {
	Url             string `json:"ul"`
	Database        string `json:"db"`
	Collection      string `json:"cl"`
	SocketTimeoutMs int    `json:"sto"`
	Command         int    `json:"cm"`
	Data            string `json:"dt"`
}

type UpdateOneParams struct {
	Filter   string `json:"f"`
	Update   string `json:"u"`
	OpUpsert bool   `json:"ou"`
}

type FindOneParams struct {
	Filter string `json:"f"`
}

type AggregateParams struct {
	Pipeline  string `json:"p"`
	BatchSize int    `json:"bs"`
}

type StatefulNextParams struct {
	Command int `json:"cm"`
}

type CreateIndexParams struct {
	Keys string `json:"k"`
	Name string `json:"n"`
}

type DeleteOneParams struct {
	Filter string `json:"f"`
}

type DeleteManyParams struct {
	Filter string `json:"f"`
}
