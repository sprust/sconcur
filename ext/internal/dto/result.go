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
}

func NewSuccessResult(message *Message, payload string, executionMs int) *Result {
	return &Result{
		FlowKey:     message.FlowKey,
		Method:      message.Method,
		TaskKey:     message.TaskKey,
		IsError:     false,
		Payload:     payload,
		ExecutionMs: executionMs,
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
	}
}

func NewErrorResult(message *Message, payload string) *Result {
	return &Result{
		FlowKey: message.FlowKey,
		Method:  message.Method,
		TaskKey: message.TaskKey,
		IsError: true,
		Payload: payload,
	}
}
