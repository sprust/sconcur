package connection

import (
	"context"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/features/mongodb/serializer"
	"sconcur/internal/features/mongodb/states/aggregation_state"
	"sconcur/internal/features/mongodb/states/find_state"
	"sconcur/internal/states"
	"strconv"

	"go.mongodb.org/mongo-driver/v2/bson"
	"go.mongodb.org/mongo-driver/v2/mongo"
	"go.mongodb.org/mongo-driver/v2/mongo/options"
)

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

	return documentResult(message, "insertOne", func() (interface{}, error) {
		return c.mCollection.InsertOne(ctx, doc)
	})
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

	return documentResult(message, "bulkWrite", func() (interface{}, error) {
		return c.mCollection.BulkWrite(ctx, models)
	})
}

func (c *Collection) Aggregate(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.AggregateParams

	err := objects.UnmarshalParams(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse aggregate params", err),
		)
	}

	pipeline, err := serializer.UnmarshalPipeline(params.Pipeline)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse aggregate payload", err),
		)
	}

	// The cursor outlives this handler, so keep the client owner alive until the state
	// is closed (last batch or cancellation).
	client := c.database.client
	client.Retain()

	state := aggregation_state.New(
		ctx,
		message,
		c.mCollection,
		pipeline,
		params.BatchSize,
		errFactory,
		client.Release,
	)

	result, err := states.Get().Start(ctx, message.TaskKey, state)

	if err != nil {
		client.Release()

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

	return documentResult(message, "insertMany", func() (interface{}, error) {
		return c.mCollection.InsertMany(ctx, docs)
	})
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

	return stringResult(message, "countDocuments", func() (string, error) {
		result, err := c.mCollection.CountDocuments(ctx, filter)

		if err != nil {
			return "", err
		}

		return strconv.FormatInt(result, 10), nil
	})
}

func (c *Collection) UpdateOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.UpdateParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	opts := options.UpdateOne()

	if params.Upsert {
		opts.SetUpsert(true)
	}

	if err := applyUpdateOptions(opts, &params); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("updateOne options", err),
		)
	}

	return documentResult(message, "updateOne", func() (interface{}, error) {
		return c.mCollection.UpdateOne(ctx, filter, update, opts)
	})
}

func (c *Collection) FindOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindOneParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	opts := options.FindOne()

	if len(params.Projection) > 0 {
		projection, err := serializer.UnmarshalDocument(params.Projection)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse findOne projection", err),
			)
		}

		opts.SetProjection(projection)
	}

	if err := applyFindOneOptions(opts, params.Hint, params.Collation); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("findOne options", err),
		)
	}

	return singleResult(message, "findOne", func() *mongo.SingleResult {
		return c.mCollection.FindOne(ctx, filter, opts)
	})
}

func (c *Collection) CreateIndex(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.CreateIndexParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	model := mongo.IndexModel{
		Keys:    keys,
		Options: options.Index().SetName(params.Name),
	}

	return stringResult(message, "createIndex", func() (string, error) {
		return c.mCollection.Indexes().CreateOne(ctx, model)
	})
}

func (c *Collection) DeleteOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.DeleteOneParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	opts := options.DeleteOne()

	if err := applyDeleteOptions(opts, params.Hint, params.Collation); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("deleteOne options", err),
		)
	}

	return documentResult(message, "deleteOne", func() (interface{}, error) {
		return c.mCollection.DeleteOne(ctx, filter, opts)
	})
}

func (c *Collection) DeleteMany(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.DeleteManyParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	opts := options.DeleteMany()

	if err := applyDeleteOptions(opts, params.Hint, params.Collation); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("deleteMany options", err),
		)
	}

	return documentResult(message, "deleteMany", func() (interface{}, error) {
		return c.mCollection.DeleteMany(ctx, filter, opts)
	})
}

func (c *Collection) UpdateMany(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.UpdateParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	opts := options.UpdateMany()

	if params.Upsert {
		opts.SetUpsert(true)
	}

	if err := applyUpdateOptions(opts, &params); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("updateMany options", err),
		)
	}

	return documentResult(message, "updateMany", func() (interface{}, error) {
		return c.mCollection.UpdateMany(ctx, filter, update, opts)
	})
}

func (c *Collection) Drop(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	return stringResult(message, "drop", func() (string, error) {
		return "", c.mCollection.Drop(ctx)
	})
}

func (c *Collection) DropIndex(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.DropIndexParams

	err := objects.UnmarshalParams(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse dropIndex params", err),
		)
	}

	return stringResult(message, "dropIndex", func() (string, error) {
		return params.Name, c.mCollection.Indexes().DropOne(ctx, params.Name)
	})
}

func (c *Collection) Find(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	if err := applyFindOptions(opts, params.Hint, params.Collation); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("find options", err),
		)
	}

	// The cursor outlives this handler, so keep the client owner alive until the state
	// is closed (last batch or cancellation).
	client := c.database.client
	client.Retain()

	state := find_state.New(
		ctx,
		message,
		c.mCollection,
		filter,
		opts,
		params.BatchSize,
		errFactory,
		client.Release,
	)

	result, err := states.Get().Start(ctx, message.TaskKey, state)

	if err != nil {
		client.Release()

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

	err := objects.UnmarshalParams(payload.Data, &params)

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

	opts := options.Distinct()

	if err := applyDistinctOptions(opts, params.Collation); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("distinct options", err),
		)
	}

	return documentResult(message, "distinct", func() (interface{}, error) {
		result := c.mCollection.Distinct(ctx, params.FieldName, filter, opts)

		if err := result.Err(); err != nil {
			return nil, err
		}

		var values []interface{}

		if err := result.Decode(&values); err != nil {
			return nil, err
		}

		return bson.D{{Key: "values", Value: values}}, nil
	})
}

func (c *Collection) FindOneAndUpdate(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindOneAndUpdateParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	if err := applyFindOneAndUpdateOptions(opts, &params); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("findOneAndUpdate options", err),
		)
	}

	return singleResult(message, "findOneAndUpdate", func() *mongo.SingleResult {
		return c.mCollection.FindOneAndUpdate(ctx, filter, update, opts)
	})
}

func (c *Collection) FindOneAndDelete(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindOneAndDeleteParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	var opts *options.FindOneAndDeleteOptionsBuilder

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

	return singleResult(message, "findOneAndDelete", func() *mongo.SingleResult {
		return c.mCollection.FindOneAndDelete(ctx, filter, opts)
	})
}

func (c *Collection) FindOneAndReplace(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.FindOneAndReplaceParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	return singleResult(message, "findOneAndReplace", func() *mongo.SingleResult {
		return c.mCollection.FindOneAndReplace(ctx, filter, replacement, opts)
	})
}

func (c *Collection) ReplaceOne(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.ReplaceOneParams

	err := objects.UnmarshalParams(payload.Data, &params)

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

	var opts *options.ReplaceOptionsBuilder

	if params.Upsert {
		opts = options.Replace().SetUpsert(true)
	}

	return documentResult(message, "replaceOne", func() (interface{}, error) {
		return c.mCollection.ReplaceOne(ctx, filter, replacement, opts)
	})
}

func (c *Collection) EstimatedDocumentCount(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	return stringResult(message, "estimatedDocumentCount", func() (string, error) {
		result, err := c.mCollection.EstimatedDocumentCount(ctx)

		if err != nil {
			return "", err
		}

		return strconv.FormatInt(result, 10), nil
	})
}

func (c *Collection) CreateIndexes(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	indexesValue, err := bson.Raw(payload.Data).LookupErr("ix")

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse createIndexes params", err),
		)
	}

	indexesArray, ok := indexesValue.ArrayOK()

	if !ok {
		return dto.NewErrorResult(
			message,
			errFactory.ByText("createIndexes: ix is not an array"),
		)
	}

	elements, err := indexesArray.Values()

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse createIndexes indexes", err),
		)
	}

	models := make([]mongo.IndexModel, len(elements))

	for i, element := range elements {
		index, ok := element.DocumentOK()

		if !ok {
			return dto.NewErrorResult(
				message,
				errFactory.ByText("createIndexes: index is not a document"),
			)
		}

		keys, err := serializer.UnmarshalDocument(index.Lookup("k").Value)

		if err != nil {
			return dto.NewErrorResult(
				message,
				errFactory.ByErr("parse createIndexes keys", err),
			)
		}

		name, ok := index.Lookup("n").StringValueOK()

		if !ok {
			return dto.NewErrorResult(
				message,
				errFactory.ByText("createIndexes: name is not a string"),
			)
		}

		models[i] = mongo.IndexModel{
			Keys:    keys,
			Options: options.Index().SetName(name),
		}
	}

	return documentResult(message, "createIndexes", func() (interface{}, error) {
		result, err := c.mCollection.Indexes().CreateMany(ctx, models)

		if err != nil {
			return nil, err
		}

		return bson.D{{Key: "names", Value: result}}, nil
	})
}

func (c *Collection) ListIndexes(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	return stringResult(message, "listIndexes", func() (string, error) {
		cursor, err := c.mCollection.Indexes().List(ctx)

		if err != nil {
			return "", err
		}

		defer cursor.Close(ctx)

		var indexes []bson.Raw

		for cursor.Next(ctx) {
			indexes = append(indexes, cloneRaw(cursor.Current))
		}

		if err := cursor.Err(); err != nil {
			return "", err
		}

		return serializer.MarshalDocumentBatchRaw(indexes)
	})
}

func cloneRaw(raw bson.Raw) bson.Raw {
	return append(bson.Raw(nil), raw...)
}
