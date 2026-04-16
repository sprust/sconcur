package dto

import "sconcur/internal/types"

type Message struct {
	FlowKey string       `json:"fk" msgpack:"fk"`
	Method  types.Method `json:"md" msgpack:"md"`
	TaskKey string       `json:"tk" msgpack:"tk"`
	Payload string       `json:"pl" msgpack:"pl"`
	IsNext  bool         `json:"nx" msgpack:"nx"`
}
