package mongodb_feature

import (
	"context"
	"encoding/json"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb_feature/connections"
	"sconcur/internal/features/mongodb_feature/helpers"
	"sconcur/internal/tasks"
	"strconv"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
)

const resultKey = "_result"

var _ contracts.MessageHandler = (*Feature)(nil)

var errFactory = errs.NewErrorsFactory("mongodb")

type Feature struct {
	connections *connections.Connections
}

func New(connections *connections.Connections) *Feature {
	return &Feature{
		connections: connections,
	}
}

func (f *Feature) Handle(task *tasks.Task) {
	message := task.Msg()

	var payload Payload

	err := json.Unmarshal([]byte(message.Payload), &payload)

	if err != nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByErr("parse payload error", err),
			),
		)

		return
	}

	ctx, ctxCancel := context.WithCancel(context.Background())

	go func() {
		select {
		case <-task.Ctx().Done():
			ctxCancel()
		}
	}()

	collection, err := f.connections.Get(
		ctx,
		payload.Url,
		payload.Database,
		payload.Collection,
	)

	if err != nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByErr("connection", err),
			),
		)

		return
	}

	if payload.Command == 1 {
		task.AddResult(
			f.insertOne(
				ctx,
				message,
				&payload,
				collection,
			),
		)
	} else if payload.Command == 2 {
		task.AddResult(
			f.bulkWrite(
				ctx,
				message,
				&payload,
				collection,
			),
		)
	} else if payload.Command == 3 {
		task.AddResult(
			f.aggregate(
				ctx,
				task,
				message,
				&payload,
				collection,
			),
		)
	} else if payload.Command == 4 {
		task.AddResult(
			f.insertMany(
				ctx,
				message,
				&payload,
				collection,
			),
		)
	} else if payload.Command == 5 {
		task.AddResult(
			f.countDocuments(
				ctx,
				message,
				&payload,
				collection,
			),
		)
	} else {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByText("unknown command"),
			),
		)
	}
}

func (f *Feature) insertOne(
	ctx context.Context,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	doc, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse insertOne payload", err),
		)
	}

	result, err := collection.InsertOne(ctx, doc)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("insertOne error", err),
		)
	}

	serializedResult, err := helpers.MarshalResult(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal insertOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
}

func (f *Feature) bulkWrite(
	ctx context.Context,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	models, err := helpers.UnmarshalModels(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse bulkWrite payload", err),
		)
	}

	result, err := collection.BulkWrite(ctx, models)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("bulkWrite error", err),
		)
	}

	serializedResult, err := helpers.MarshalResult(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal bulkWrite result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
}

func (f *Feature) aggregate(
	ctx context.Context,
	task *tasks.Task,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	pipeline, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse aggregate payload", err),
		)
	}

	cursor, err := collection.Aggregate(ctx, pipeline)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("aggregate error", err),
		)
	}

	maxBatchCount := 20

	var items []interface{}

	var response string

	for cursor.Next(ctx) {
		if err := cursor.Err(); err != nil {
			_ = cursor.Close(ctx)

			return dto.NewErrorResult(
				message,
				errFactory.ByErr("aggregate cursor error", err),
			)
		}

		items = append(items, cursor.Current)

		if len(items) == maxBatchCount {
			response, err = helpers.MarshalResult(
				bson.D{
					{Key: resultKey, Value: items},
				},
			)

			if err != nil {
				return dto.NewErrorResult(
					message,
					errFactory.ByErr("aggregate result marshal error", err),
				)
			}

			task.AddResult(
				dto.NewSuccessResultWithNext(message, response),
			)

			items = []interface{}{}
		}
	}

	response, err = helpers.MarshalResult(
		bson.D{
			{Key: resultKey, Value: items},
		},
	)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("aggregate result marshal error", err),
		)
	}

	return dto.NewSuccessResult(message, response)
}

func (f *Feature) insertMany(
	ctx context.Context,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	docs, err := helpers.UnmarshalDocuments(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse insertMany payload", err),
		)
	}

	result, err := collection.InsertMany(ctx, docs)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("insertMany error", err),
		)
	}

	serializedResult, err := helpers.MarshalResult(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal insertMany result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
}

func (f *Feature) countDocuments(
	ctx context.Context,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	filter, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse countDocuments payload", err),
		)
	}

	result, err := collection.CountDocuments(ctx, filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("countDocuments error", err),
		)
	}

	return dto.NewSuccessResult(message, strconv.FormatInt(result, 10))
}
