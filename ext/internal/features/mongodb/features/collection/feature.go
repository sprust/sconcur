package collection_feature

import (
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/connection"
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/tasks"
	"sync"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*CollectionFeature)(nil)

var once sync.Once
var instance *CollectionFeature

var errFactory = errs.NewErrorsFactory("mongodb")

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

	var payload objects.Payload

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

	client, err := f.clients.GetClient(
		ctx,
		payload.Url,
		payload.SocketTimeoutMs,
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

	database := client.Database(payload.Database)

	// Database/client-level commands
	switch {
	case payload.Command == 23:
		task.AddResult(
			database.ListCollections(ctx, message, &payload),
		)
		return
	case payload.Command == 24:
		task.AddResult(
			database.ListDatabases(ctx, message, &payload),
		)
		return
	case payload.Command == 25:
		task.AddResult(
			database.RenameCollection(ctx, message, &payload),
		)
		return
	case payload.Command == 26:
		task.AddResult(
			database.RunCommand(ctx, message, &payload),
		)
		return
	}

	collection := database.Collection(payload.Collection)

	// Collection-level commands
	switch {
	case payload.Command == 1:
		task.AddResult(
			collection.InsertOne(ctx, message, &payload),
		)
	case payload.Command == 2:
		task.AddResult(
			collection.BulkWrite(ctx, message, &payload),
		)
	case payload.Command == 3:
		task.AddResult(
			collection.Aggregate(ctx, message, &payload),
		)
	case payload.Command == 4:
		task.AddResult(
			collection.InsertMany(ctx, message, &payload),
		)
	case payload.Command == 5:
		task.AddResult(
			collection.CountDocuments(ctx, message, &payload),
		)
	case payload.Command == 6:
		task.AddResult(
			collection.UpdateOne(ctx, message, &payload),
		)
	case payload.Command == 7:
		task.AddResult(
			collection.FindOne(ctx, message, &payload),
		)
	case payload.Command == 8:
		task.AddResult(
			collection.CreateIndex(ctx, message, &payload),
		)
	case payload.Command == 9:
		task.AddResult(
			collection.DeleteOne(ctx, message, &payload),
		)
	case payload.Command == 10:
		task.AddResult(
			collection.DeleteMany(ctx, message, &payload),
		)
	case payload.Command == 11:
		task.AddResult(
			collection.UpdateMany(ctx, message, &payload),
		)
	case payload.Command == 12:
		task.AddResult(
			collection.Drop(ctx, message, &payload),
		)
	case payload.Command == 13:
		task.AddResult(
			collection.DropIndex(ctx, message, &payload),
		)
	case payload.Command == 14:
		task.AddResult(
			collection.Find(ctx, message, &payload),
		)
	case payload.Command == 15:
		task.AddResult(
			collection.Distinct(ctx, message, &payload),
		)
	case payload.Command == 16:
		task.AddResult(
			collection.FindOneAndUpdate(ctx, message, &payload),
		)
	case payload.Command == 17:
		task.AddResult(
			collection.FindOneAndDelete(ctx, message, &payload),
		)
	case payload.Command == 18:
		task.AddResult(
			collection.FindOneAndReplace(ctx, message, &payload),
		)
	case payload.Command == 19:
		task.AddResult(
			collection.ReplaceOne(ctx, message, &payload),
		)
	case payload.Command == 20:
		task.AddResult(
			collection.EstimatedDocumentCount(ctx, message, &payload),
		)
	case payload.Command == 21:
		task.AddResult(
			collection.CreateIndexes(ctx, message, &payload),
		)
	case payload.Command == 22:
		task.AddResult(
			collection.ListIndexes(ctx, message, &payload),
		)
	default:
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByText("unknown command"),
			),
		)
	}
}
