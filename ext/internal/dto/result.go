package dto

import "sconcur/internal/types"

type Result struct {
	FlowKey  string       `json:"fk"`
	Method   types.Method `json:"md"`
	TaskKey  string       `json:"tk"`
	Waitable bool         `json:"wt"`
	IsError  bool         `json:"er"`
	Payload  string       `json:"pl"`
	HasNext  bool         `json:"hn"`
}
