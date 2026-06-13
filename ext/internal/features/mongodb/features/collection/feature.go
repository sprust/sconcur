package collection_feature

import (
	"context"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/connection"
	"sconcur/internal/features/mongodb/payloads"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"sync"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*CollectionFeature)(nil)

var once sync.Once
var instance *CollectionFeature

var errFactory = errs.NewErrorsFactory("mongodb")

type collectionHandler = func(*connection.Collection, context.Context, *dto.Message, *payloads.Payload) *dto.Result
type databaseHandler = func(*connection.Database, context.Context, *dto.Message, *payloads.Payload) *dto.Result

// databaseHandlers/collectionHandlers map a command to its handler. Adding a command is a
// single entry here plus the handler method — no dispatch switch to touch.
var databaseHandlers = map[types.MongodbCommand]databaseHandler{
	types.MongodbListCollections:  (*connection.Database).ListCollections,
	types.MongodbListDatabases:    (*connection.Database).ListDatabases,
	types.MongodbRenameCollection: (*connection.Database).RenameCollection,
	types.MongodbRunCommand:       (*connection.Database).RunCommand,
}

var collectionHandlers = map[types.MongodbCommand]collectionHandler{
	types.MongodbInsertOne:              (*connection.Collection).InsertOne,
	types.MongodbBulkWrite:              (*connection.Collection).BulkWrite,
	types.MongodbAggregate:              (*connection.Collection).Aggregate,
	types.MongodbInsertMany:             (*connection.Collection).InsertMany,
	types.MongodbCountDocuments:         (*connection.Collection).CountDocuments,
	types.MongodbUpdateOne:              (*connection.Collection).UpdateOne,
	types.MongodbFindOne:                (*connection.Collection).FindOne,
	types.MongodbCreateIndex:            (*connection.Collection).CreateIndex,
	types.MongodbDeleteOne:              (*connection.Collection).DeleteOne,
	types.MongodbDeleteMany:             (*connection.Collection).DeleteMany,
	types.MongodbUpdateMany:             (*connection.Collection).UpdateMany,
	types.MongodbDrop:                   (*connection.Collection).Drop,
	types.MongodbDropIndex:              (*connection.Collection).DropIndex,
	types.MongodbFind:                   (*connection.Collection).Find,
	types.MongodbDistinct:               (*connection.Collection).Distinct,
	types.MongodbFindOneAndUpdate:       (*connection.Collection).FindOneAndUpdate,
	types.MongodbFindOneAndDelete:       (*connection.Collection).FindOneAndDelete,
	types.MongodbFindOneAndReplace:      (*connection.Collection).FindOneAndReplace,
	types.MongodbReplaceOne:             (*connection.Collection).ReplaceOne,
	types.MongodbEstimatedDocumentCount: (*connection.Collection).EstimatedDocumentCount,
	types.MongodbCreateIndexes:          (*connection.Collection).CreateIndexes,
	types.MongodbListIndexes:            (*connection.Collection).ListIndexes,
}

type CollectionFeature struct {
	clients *connection.Clients
}

func GetCollectionFeature() *CollectionFeature {
	once.Do(func() {
		instance = &CollectionFeature{
			clients: connection.GetClients(),
		}
	})

	return instance
}

func (f *CollectionFeature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var payload payloads.Payload

	err := msgpack.Unmarshal(message.Payload, &payload)

	if err != nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByErr("parse payload error", err),
			),
		)

		return
	}

	ctx := task.GetContext()

	client, err := f.clients.Acquire(
		payload.Url,
		payload.TimeoutMs,
		payload.ServerSelectionTimeoutMs,
	)

	if err != nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByErr("client", err),
			),
		)

		return
	}

	// Release the handler's hold when Handle returns. A streaming command additionally
	// retains the client for the lifetime of its cursor (released on the state's Close).
	defer client.Release()

	database := client.Database(payload.Database)

	if handle, ok := databaseHandlers[payload.Command]; ok {
		task.AddResult(handle(database, ctx, message, &payload))

		return
	}

	if handle, ok := collectionHandlers[payload.Command]; ok {
		collection := database.Collection(payload.Collection)

		task.AddResult(handle(collection, ctx, message, &payload))

		return
	}

	task.AddResult(
		dto.NewErrorResult(
			message,
			errFactory.ByText("unknown command"),
		),
	)
}
