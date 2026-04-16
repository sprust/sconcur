package params

type SleepPayload struct {
	Milliseconds int64 `json:"ms" msgpack:"ms"`
}
