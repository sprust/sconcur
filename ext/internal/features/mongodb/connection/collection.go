package connection

import (
	"context"
	"encoding/json"
	"errors"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/features/mongodb/serializer"
	"sconcur/internal/features/mongodb/stateful/aggregate_stateful"
	"sconcur/internal/helpers"
	"strconv"
	"time"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

const resultKey = "_r"

var errFactory = errs.NewErrorsFactory("mongodb")

type Collection struct {
	database    *Database
	mCollection *mongo.Collection
}

func NewCollection(database *Database, mCollection *mongo.Collection) *Collection {
	return &Collection{
		database:    database,
		mCollection: mCollection,
	}
}

func (c *Collection) InsertOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	doc, err := serializer.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse insertOne payload", err),
		)
	}

	start := time.Now()
	result, err := c.mCollection.InsertOne(ctx, doc)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("insertOne error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal insertOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) BulkWrite(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	models, err := serializer.UnmarshalBulkWriteModels(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse bulkWrite payload", err),
		)
	}

	start := time.Now()
	result, err := c.mCollection.BulkWrite(ctx, models)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("bulkWrite error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal bulkWrite result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) Aggregate(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.AggregateParams

	err := json.Unmarshal([]byte(payload.Data), &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse aggregate params", err),
		)
	}

	pipeline, err := serializer.UnmarshalDocument(params.Pipeline)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse aggregate payload", err),
		)
	}

	states := aggregate_stateful.GetAggregates()

	state := states.GetState(message.TaskKey)

	if state != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByText("aggregate already started"),
		)
	}

	state = aggregate_stateful.NewAggregateState(
		ctx,
		message,
		c.mCollection,
		pipeline,
		params.BatchSize,
		resultKey,
		errFactory,
	)

	err = states.AddState(ctx, message.TaskKey, state)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("aggregate", err),
		)
	}

	result := state.Next()

	if !result.HasNext {
		states.DeleteState(message.TaskKey)
	}

	return result
}

func (c *Collection) InsertMany(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	docs, err := serializer.UnmarshalDocuments(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse insertMany payload", err),
		)
	}

	start := time.Now()
	result, err := c.mCollection.InsertMany(ctx, docs)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("insertMany error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal insertMany result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) CountDocuments(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	filter, err := serializer.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse countDocuments payload", err),
		)
	}

	start := time.Now()
	result, err := c.mCollection.CountDocuments(ctx, filter)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("countDocuments error", err),
		)
	}

	return dto.NewSuccessResult(message, strconv.FormatInt(result, 10), executionMs)
}

func (c *Collection) UpdateOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.UpdateOneParams

	err := json.Unmarshal([]byte(payload.Data), &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse updateOne params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse updateOne filter", err),
		)
	}

	update, err := serializer.UnmarshalDocument(params.Update)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse updateOne update", err),
		)
	}

	var opts *options.UpdateOptions

	if params.Upsert {
		opts = options.Update().SetUpsert(true)
	}

	start := time.Now()
	result, err := c.mCollection.UpdateOne(ctx, filter, update, opts)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("updateOne error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal updateOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) FindOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindOneParams

	err := json.Unmarshal([]byte(payload.Data), &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOne params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOne filter", err),
		)
	}

	var opts *options.FindOneOptions

	if params.Protection != "" {
		protection, err := serializer.UnmarshalDocument(params.Protection)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse findOne protection", err),
			)
		}

		opts = options.FindOne().SetProjection(protection)
	}

	start := time.Now()
	result := c.mCollection.FindOne(ctx, filter, opts)
	executionMs := helpers.CalcExecutionMs(start)

	err = result.Err()

	if err != nil {
		if errors.Is(err, mongo.ErrNoDocuments) {
			serializedResult, marshalErr := serializer.MarshalDocument(bson.D{})

			if marshalErr != nil {
				return dto.NewErrorResult(
					message,
					errFactory.ByErr("marshal findOne nil result error", marshalErr),
				)
			}

			return dto.NewSuccessResult(message, serializedResult, executionMs)
		}

		return dto.NewErrorResult(
			message,
			errFactory.ByErr("findOne result error", err),
		)
	}

	raw, err := result.Raw()

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("findOne raw error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(raw)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal findOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) CreateIndex(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.CreateIndexParams

	err := json.Unmarshal([]byte(payload.Data), &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse createIndex params", err),
		)
	}

	var keys bson.D

	err = bson.UnmarshalExtJSON([]byte(params.Keys), true, &keys)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse indexes BSON error", err),
		)
	}

	var opts options.IndexOptions

	opts.Name = &params.Name

	model := mongo.IndexModel{
		Keys:    keys,
		Options: &opts,
	}

	start := time.Now()
	result, err := c.mCollection.Indexes().CreateOne(ctx, model)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("createIndex error", err),
		)
	}

	return dto.NewSuccessResult(message, result, executionMs)
}

func (c *Collection) DeleteOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.DeleteOneParams

	err := json.Unmarshal([]byte(payload.Data), &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse deleteOne params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse deleteOne filter", err),
		)
	}

	start := time.Now()
	result, err := c.mCollection.DeleteOne(ctx, filter)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("deleteOne error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal deleteOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) DeleteMany(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.DeleteManyParams

	err := json.Unmarshal([]byte(payload.Data), &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse deleteMany params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse deleteMany filter", err),
		)
	}

	start := time.Now()
	result, err := c.mCollection.DeleteMany(ctx, filter)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("deleteMany error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal deleteMany result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}
