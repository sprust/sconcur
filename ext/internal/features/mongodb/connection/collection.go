package connection

import (
	"context"
	"errors"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/features/mongodb/serializer"
	"sconcur/internal/features/mongodb/states/aggregation_state"
	"sconcur/internal/features/mongodb/states/find_state"
	"sconcur/internal/helpers"
	"sconcur/internal/states"
	"strconv"
	"time"

	"github.com/vmihailenco/msgpack/v5"
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

	err := msgpack.Unmarshal(payload.Data, &params)

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

	state := aggregation_state.New(
		ctx,
		message,
		c.mCollection,
		pipeline,
		params.BatchSize,
		resultKey,
		errFactory,
	)

	result, err := states.Get().Start(ctx, message.TaskKey, state)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("aggregate", err),
		)
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
	var params objects.UpdateParams

	err := msgpack.Unmarshal(payload.Data, &params)

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

	err := msgpack.Unmarshal(payload.Data, &params)

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

	if len(params.Projection) > 0 {
		projection, err := serializer.UnmarshalDocument(params.Projection)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse findOne projection", err),
			)
		}

		opts = options.FindOne().SetProjection(projection)
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

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse createIndex params", err),
		)
	}

	keys, err := serializer.UnmarshalDocument(params.Keys)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse createIndex keys", err),
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

	err := msgpack.Unmarshal(payload.Data, &params)

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

	err := msgpack.Unmarshal(payload.Data, &params)

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

func (c *Collection) UpdateMany(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.UpdateParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse updateMany params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse updateMany filter", err),
		)
	}

	update, err := serializer.UnmarshalDocument(params.Update)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse updateMany update", err),
		)
	}

	var opts *options.UpdateOptions

	if params.Upsert {
		opts = options.Update().SetUpsert(true)
	}

	start := time.Now()
	result, err := c.mCollection.UpdateMany(ctx, filter, update, opts)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("updateMany error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal updateMany result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) Drop(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	start := time.Now()

	err := c.mCollection.Drop(ctx)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("drop error", err),
		)
	}

	executionMs := helpers.CalcExecutionMs(start)

	return dto.NewSuccessResult(message, "", executionMs)
}

func (c *Collection) DropIndex(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.DropIndexParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse dropIndex params", err),
		)
	}

	start := time.Now()
	result, err := c.mCollection.Indexes().DropOne(ctx, params.Name)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("dropIndex error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal dropIndex result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) Find(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse find params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse find filter", err),
		)
	}

	opts := options.Find()

	if len(params.Projection) > 0 {
		projection, err := serializer.UnmarshalDocument(params.Projection)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse find projection", err),
			)
		}

		opts.SetProjection(projection)
	}

	if len(params.Sort) > 0 {
		sort, err := serializer.UnmarshalDocument(params.Sort)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse find sort", err),
			)
		}

		opts.SetSort(sort)
	}

	if params.Limit > 0 {
		opts.SetLimit(params.Limit)
	}

	if params.Skip > 0 {
		opts.SetSkip(params.Skip)
	}

	if params.BatchSize > 0 {
		opts.SetBatchSize(int32(params.BatchSize))
	}

	state := find_state.New(
		ctx,
		message,
		c.mCollection,
		filter,
		opts,
		params.BatchSize,
		errFactory,
	)

	result, err := states.Get().Start(ctx, message.TaskKey, state)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("find", err),
		)
	}

	return result
}

func (c *Collection) Distinct(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.DistinctParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse distinct params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse distinct filter", err),
		)
	}

	start := time.Now()
	result, err := c.mCollection.Distinct(ctx, params.FieldName, filter)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("distinct error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(bson.D{
		{Key: "values", Value: result},
	})

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal distinct result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) FindOneAndUpdate(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindOneAndUpdateParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOneAndUpdate params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOneAndUpdate filter", err),
		)
	}

	update, err := serializer.UnmarshalDocument(params.Update)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOneAndUpdate update", err),
		)
	}

	opts := options.FindOneAndUpdate()

	if params.Upsert {
		opts.SetUpsert(true)
	}

	if params.ReturnDocument {
		opts.SetReturnDocument(options.After)
	}

	if len(params.Projection) > 0 {
		projection, err := serializer.UnmarshalDocument(params.Projection)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse findOneAndUpdate projection", err),
			)
		}

		opts.SetProjection(projection)
	}

	start := time.Now()
	result := c.mCollection.FindOneAndUpdate(ctx, filter, update, opts)
	executionMs := helpers.CalcExecutionMs(start)

	return c.handleSingleResult(message, result, "findOneAndUpdate", executionMs)
}

func (c *Collection) FindOneAndDelete(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindOneAndDeleteParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOneAndDelete params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOneAndDelete filter", err),
		)
	}

	var opts *options.FindOneAndDeleteOptions

	if len(params.Projection) > 0 {
		projection, err := serializer.UnmarshalDocument(params.Projection)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse findOneAndDelete projection", err),
			)
		}

		opts = options.FindOneAndDelete().SetProjection(projection)
	}

	start := time.Now()
	result := c.mCollection.FindOneAndDelete(ctx, filter, opts)
	executionMs := helpers.CalcExecutionMs(start)

	return c.handleSingleResult(message, result, "findOneAndDelete", executionMs)
}

func (c *Collection) FindOneAndReplace(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindOneAndReplaceParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOneAndReplace params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOneAndReplace filter", err),
		)
	}

	replacement, err := serializer.UnmarshalDocument(params.Replacement)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse findOneAndReplace replacement", err),
		)
	}

	opts := options.FindOneAndReplace()

	if params.Upsert {
		opts.SetUpsert(true)
	}

	if params.ReturnDocument {
		opts.SetReturnDocument(options.After)
	}

	if len(params.Projection) > 0 {
		projection, err := serializer.UnmarshalDocument(params.Projection)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse findOneAndReplace projection", err),
			)
		}

		opts.SetProjection(projection)
	}

	start := time.Now()
	result := c.mCollection.FindOneAndReplace(ctx, filter, replacement, opts)
	executionMs := helpers.CalcExecutionMs(start)

	return c.handleSingleResult(message, result, "findOneAndReplace", executionMs)
}

func (c *Collection) ReplaceOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.ReplaceOneParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse replaceOne params", err),
		)
	}

	filter, err := serializer.UnmarshalDocument(params.Filter)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse replaceOne filter", err),
		)
	}

	replacement, err := serializer.UnmarshalDocument(params.Replacement)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse replaceOne replacement", err),
		)
	}

	var opts *options.ReplaceOptions

	if params.Upsert {
		opts = options.Replace().SetUpsert(true)
	}

	start := time.Now()
	result, err := c.mCollection.ReplaceOne(ctx, filter, replacement, opts)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("replaceOne error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(result)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal replaceOne result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) EstimatedDocumentCount(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	start := time.Now()
	result, err := c.mCollection.EstimatedDocumentCount(ctx)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("estimatedDocumentCount error", err),
		)
	}

	return dto.NewSuccessResult(message, strconv.FormatInt(result, 10), executionMs)
}

func (c *Collection) CreateIndexes(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.CreateIndexesParams

	err := msgpack.Unmarshal(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse createIndexes params", err),
		)
	}

	models := make([]mongo.IndexModel, len(params.Indexes))

	for i, idx := range params.Indexes {
		keys, err := serializer.UnmarshalDocument(idx.Keys)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse createIndexes keys", err),
			)
		}

		name := idx.Name
		models[i] = mongo.IndexModel{
			Keys:    keys,
			Options: &options.IndexOptions{Name: &name},
		}
	}

	start := time.Now()
	result, err := c.mCollection.Indexes().CreateMany(ctx, models)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("createIndexes error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(bson.D{
		{Key: "names", Value: result},
	})

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal createIndexes result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) ListIndexes(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	start := time.Now()
	cursor, err := c.mCollection.Indexes().List(ctx)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("listIndexes error", err),
		)
	}

	defer cursor.Close(ctx)

	var indexes []bson.Raw

	for cursor.Next(ctx) {
		indexes = append(indexes, cloneRaw(cursor.Current))
	}

	if err := cursor.Err(); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("listIndexes cursor error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocumentBatchRaw(indexes)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal listIndexes result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (c *Collection) handleSingleResult(
	message *dto.Message,
	result *mongo.SingleResult,
	opName string,
	executionMs int,
) *dto.Result {
	err := result.Err()

	if err != nil {
		if errors.Is(err, mongo.ErrNoDocuments) {
			return dto.NewSuccessResult(message, "", executionMs)
		}

		return dto.NewErrorResult(
			message,
			errFactory.ByErr(opName+" error", err),
		)
	}

	raw, err := result.Raw()

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr(opName+" raw error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(raw)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal "+opName+" result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func cloneRaw(raw bson.Raw) bson.Raw {
	return append(bson.Raw(nil), raw...)
}
