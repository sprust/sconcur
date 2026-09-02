package dto

import "sconcur/internal/types"

type Message struct {
	FlowKey string       `json:"fk" msgpack:"fk"`
	Method  types.Method `json:"md" msgpack:"md"`
	TaskKey string       `json:"tk" msgpack:"tk"`
	Payload []byte       `json:"pl" msgpack:"pl"`
	IsNext  bool         `json:"nx" msgpack:"nx"`
	// OwnerId is the opaque PHP-side coroutine id awaiting this task's result
	// (0 = nobody). Carried into the Result frame so the PHP scheduler routes
	// the result without its own task-to-fiber map.
	OwnerId int64 `json:"-" msgpack:"-"`
}
