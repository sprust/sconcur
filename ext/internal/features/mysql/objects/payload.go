package objects

type Payload struct {
	Dsn       string `json:"dsn"`
	Sql       string `json:"sql"`
	Bindings  string `json:"bd"`
	Command   int    `json:"cm"`
	TxKey     string `json:"tx"`
	Isolation int    `json:"iso"`
	TimeoutMs int    `json:"to"`
}
