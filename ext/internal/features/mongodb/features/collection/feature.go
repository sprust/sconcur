package collection_feature

import (
	"encoding/json"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/connection"
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/tasks"
	"sync"
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

	collection := client.Database(payload.Database).Collection(payload.Collection)

	switch {
	case payload.Command == 1:
		task.AddResult(
			collection.InsertOne(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 2:
		task.AddResult(
			collection.BulkWrite(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 3:
		task.AddResult(
			collection.Aggregate(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 4:
		task.AddResult(
			collection.InsertMany(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 5:
		task.AddResult(
			collection.CountDocuments(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 6:
		task.AddResult(
			collection.UpdateOne(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 7:
		task.AddResult(
			collection.FindOne(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 8:
		task.AddResult(
			collection.CreateIndex(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 9:
		task.AddResult(
			collection.DeleteOne(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 10:
		task.AddResult(
			collection.DeleteMany(
				ctx,
				message,
				&payload,
			),
		)
	case payload.Command == 11:
		task.AddResult(
			collection.UpdateMany(
				ctx,
				message,
				&payload,
			),
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
