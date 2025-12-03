package mongodb_feature

type Payload struct {
	Url        string `json:"ul"`
	Database   string `json:"db"`
	Collection string `json:"cl"`
	Command    int    `json:"cm"`
	Data       string `json:"dt"`
}
