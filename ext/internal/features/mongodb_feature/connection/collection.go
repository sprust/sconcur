package connection

import (
	"context"
	"encoding/json"
	"errors"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb_feature/helpers"
	"sconcur/internal/features/mongodb_feature/objects"
	"sconcur/internal/tasks"
	"strconv"

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
	doc, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse insertOne payload", err),
		)
	}

	result, err := c.mCollection.InsertOne(ctx, doc)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("insertOne error", err),
		)
	}

	serializedResult, err := helpers.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal insertOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
}

func (c *Collection) BulkWrite(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	models, err := helpers.UnmarshalBulkWriteModels(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse bulkWrite payload", err),
		)
	}

	result, err := c.mCollection.BulkWrite(ctx, models)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("bulkWrite error", err),
		)
	}

	serializedResult, err := helpers.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal bulkWrite result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
}

func (c *Collection) Aggregate(
	ctx context.Context,
	task *tasks.Task,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	pipeline, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse aggregate payload", err),
		)
	}

	cursor, err := c.mCollection.Aggregate(ctx, pipeline)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("aggregate error", err),
		)
	}

	maxBatchCount := 50

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
			response, err = helpers.MarshalDocument(
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

	response, err = helpers.MarshalDocument(
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

func (c *Collection) InsertMany(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	docs, err := helpers.UnmarshalDocuments(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse insertMany payload", err),
		)
	}

	result, err := c.mCollection.InsertMany(ctx, docs)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("insertMany error", err),
		)
	}

	serializedResult, err := helpers.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal insertMany result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
}

func (c *Collection) CountDocuments(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	filter, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse countDocuments payload", err),
		)
	}

	result, err := c.mCollection.CountDocuments(ctx, filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("countDocuments error", err),
		)
	}

	return dto.NewSuccessResult(message, strconv.FormatInt(result, 10))
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

	filter, err := helpers.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse updateOne filter", err),
		)
	}

	update, err := helpers.UnmarshalDocument(params.Update)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse updateOne update", err),
		)
	}

	var opts *options.UpdateOptions

	if params.OpUpsert {
		opts = options.Update().SetUpsert(true)
	}

	result, err := c.mCollection.UpdateOne(ctx, filter, update, opts)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("updateOne error", err),
		)
	}

	serializedResult, err := helpers.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal updateOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
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

	filter, err := helpers.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOne filter", err),
		)
	}

	var opts *options.FindOneOptions

	result := c.mCollection.FindOne(ctx, filter, opts)

	err = result.Err()

	if err != nil {
		if errors.Is(err, mongo.ErrNoDocuments) {
			serializedResult, marshalErr := helpers.MarshalDocument(bson.D{})

			if marshalErr != nil {
				return dto.NewErrorResult(
					message,
					errFactory.ByErr("marshal findOne nil result error", marshalErr),
				)
			}

			return dto.NewSuccessResult(message, serializedResult)
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

	serializedResult, err := helpers.MarshalDocument(raw)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal findOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
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

	result, err := c.mCollection.Indexes().CreateOne(ctx, model)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("createIndex error", err),
		)
	}

	return dto.NewSuccessResult(message, result)
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

	filter, err := helpers.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse deleteOne filter", err),
		)
	}

	result, err := c.mCollection.DeleteOne(ctx, filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("deleteOne error", err),
		)
	}

	serializedResult, err := helpers.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal deleteOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult)
}
