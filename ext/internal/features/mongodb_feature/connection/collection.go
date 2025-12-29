package connection

import (
	"context"
	"encoding/json"
	"errors"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	mdbDto "sconcur/internal/features/mongodb_feature/dto"
	"sconcur/internal/features/mongodb_feature/helpers"
	"sconcur/internal/tasks"
	"strconv"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

const resultKey = "_r"

var errFactory = errs.NewErrorsFactory("mongodb")

type Collection struct {
	database   *Database
	collection *mongo.Collection
}

func NewCollection(database *Database, collection *mongo.Collection) *Collection {
	return &Collection{
		database:   database,
		collection: collection,
	}
}

func (c *Collection) InsertOne(
	ctx context.Context,
	message *dto.Message,
	payload *mdbDto.Payload,
) *dto.Result {
	doc, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse insertOne payload", err),
		)
	}

	result, err := c.collection.InsertOne(ctx, doc)

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
	payload *mdbDto.Payload,
) *dto.Result {
	models, err := helpers.UnmarshalBulkWriteModels(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse bulkWrite payload", err),
		)
	}

	result, err := c.collection.BulkWrite(ctx, models)

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
	payload *mdbDto.Payload,
) *dto.Result {
	pipeline, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse aggregate payload", err),
		)
	}

	cursor, err := c.collection.Aggregate(ctx, pipeline)

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
	payload *mdbDto.Payload,
) *dto.Result {
	docs, err := helpers.UnmarshalDocuments(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse insertMany payload", err),
		)
	}

	result, err := c.collection.InsertMany(ctx, docs)

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
	payload *mdbDto.Payload,
) *dto.Result {
	filter, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse countDocuments payload", err),
		)
	}

	result, err := c.collection.CountDocuments(ctx, filter)

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
	payload *mdbDto.Payload,
) *dto.Result {
	var params mdbDto.UpdateOneParams

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

	result, err := c.collection.UpdateOne(ctx, filter, update, opts)

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
	payload *mdbDto.Payload,
) *dto.Result {
	var params mdbDto.FindOneParams

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

	result := c.collection.FindOne(ctx, filter, opts)

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
	payload *mdbDto.Payload,
) *dto.Result {
	var params mdbDto.CreateIndexParams

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
			errFactory.ByErr("parse indexzes BSON error", err),
		)
	}

	var opts options.IndexOptions

	opts.Name = &params.Name

	model := mongo.IndexModel{
		Keys:    keys,
		Options: &opts,
	}

	result, err := c.collection.Indexes().CreateOne(ctx, model)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("createIndex error", err),
		)
	}

	return dto.NewSuccessResult(message, result)
}
