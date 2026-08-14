package dto

import "sconcur/internal/types"

type Result struct {
	FlowKey     string       `json:"fk" msgpack:"fk"`
	Method      types.Method `json:"md" msgpack:"md"`
	TaskKey     string       `json:"tk" msgpack:"tk"`
	IsError     bool         `json:"er" msgpack:"er"`
	Payload     string       `json:"pl" msgpack:"pl"`
	HasNext     bool         `json:"hn" msgpack:"hn"`
	ExecutionMs int          `json:"ems" msgpack:"ems"`
	// OwnerId mirrors Message.OwnerId: the PHP coroutine awaiting this result
	// (0 = none), carried in the binary result frame.
	OwnerId int64 `json:"-" msgpack:"-"`
}

func NewSuccessResult(message *Message, payload string, executionMs int) *Result {
	return &Result{
		FlowKey:     message.FlowKey,
		Method:      message.Method,
		TaskKey:     message.TaskKey,
		IsError:     false,
		Payload:     payload,
		ExecutionMs: executionMs,
		OwnerId:     message.OwnerId,
	}
}

func NewSuccessResultWithNext(message *Message, payload string, executionMs int) *Result {
	return &Result{
		FlowKey:     message.FlowKey,
		Method:      message.Method,
		TaskKey:     message.TaskKey,
		IsError:     false,
		Payload:     payload,
		HasNext:     true,
		ExecutionMs: executionMs,
		OwnerId:     message.OwnerId,
	}
}

func NewErrorResult(message *Message, payload string) *Result {
	return &Result{
		FlowKey: message.FlowKey,
		Method:  message.Method,
		TaskKey: message.TaskKey,
		IsError: true,
		Payload: payload,
		OwnerId: message.OwnerId,
	}
}
