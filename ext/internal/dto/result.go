package dto

import "sconcur/internal/types"

type Result struct {
	FlowKey string       `json:"fk"`
	Method  types.Method `json:"md"`
	TaskKey string       `json:"tk"`
	IsError bool         `json:"er"`
	Payload string       `json:"pl"`
	HasNext bool         `json:"hn"`
}

func NewSuccessResult(message *Message, payload string) *Result {
	return &Result{
		FlowKey: message.FlowKey,
		Method:  message.Method,
		TaskKey: message.TaskKey,
		IsError: false,
		Payload: payload,
	}
}

func NewSuccessResultWithNext(message *Message, payload string) *Result {
	return &Result{
		FlowKey: message.FlowKey,
		Method:  message.Method,
		TaskKey: message.TaskKey,
		IsError: false,
		Payload: payload,
		HasNext: true,
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
