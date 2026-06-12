package connection

import (
	"errors"
	"sconcur/internal/dto"
	"sconcur/internal/features/mongodb/serializer"
	"sconcur/internal/helpers"
	"time"

	"go.mongodb.org/mongo-driver/mongo"
)

// documentResult runs fn, times it, BSON-marshals the returned value and wraps any error
// with the operation name. It is the shared scaffold for handlers returning a document.
func documentResult(message *dto.Message, opName string, fn func() (interface{}, error)) *dto.Result {
	start := time.Now()
	value, err := fn()
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(message, errFactory.ByErr(opName+" error", err))
	}

	serialized, err := serializer.MarshalDocument(value)

	if err != nil {
		return dto.NewErrorResult(message, errFactory.ByErr("marshal "+opName+" result error", err))
	}

	return dto.NewSuccessResult(message, serialized, executionMs)
}

// stringResult runs fn, times it and returns its string payload as-is, wrapping any error.
// Used by handlers whose result is already a plain string (counts, index names, no payload).
func stringResult(message *dto.Message, opName string, fn func() (string, error)) *dto.Result {
	start := time.Now()
	value, err := fn()
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(message, errFactory.ByErr(opName+" error", err))
	}

	return dto.NewSuccessResult(message, value, executionMs)
}

// singleResult runs fn, times it and renders a *mongo.SingleResult, mapping ErrNoDocuments
// to an empty success and wrapping any other error.
func singleResult(message *dto.Message, opName string, fn func() *mongo.SingleResult) *dto.Result {
	start := time.Now()
	result := fn()
	executionMs := helpers.CalcExecutionMs(start)

	if err := result.Err(); err != nil {
		if errors.Is(err, mongo.ErrNoDocuments) {
			return dto.NewSuccessResult(message, "", executionMs)
		}

		return dto.NewErrorResult(message, errFactory.ByErr(opName+" error", err))
	}

	raw, err := result.Raw()

	if err != nil {
		return dto.NewErrorResult(message, errFactory.ByErr(opName+" raw error", err))
	}

	serialized, err := serializer.MarshalDocument(raw)

	if err != nil {
		return dto.NewErrorResult(message, errFactory.ByErr("marshal "+opName+" result error", err))
	}

	return dto.NewSuccessResult(message, serialized, executionMs)
}
