package dto

import "sconcur/internal/types"

type Message struct {
	FlowKey string       `json:"fk"`
	Method  types.Method `json:"md"`
	TaskKey string       `json:"tk"`
	Payload string       `json:"pl"`
}
