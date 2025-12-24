package mongodb_feature

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

type CreateIndexParams struct {
	Keys string `json:"k"`
}
