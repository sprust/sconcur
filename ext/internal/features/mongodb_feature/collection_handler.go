package mongodb_feature

import (
	"encoding/json"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb_feature/connection"
	mdbDto "sconcur/internal/features/mongodb_feature/dto"
	"sconcur/internal/tasks"
	"sync"
)

var _ contracts.MessageHandler = (*CollectionHandler)(nil)

var once sync.Once
var instance *CollectionHandler

var errFactory = errs.NewErrorsFactory("mongodb")

type CollectionHandler struct {
	clients *connection.Clients
}

func GetCollectionHandler() *CollectionHandler {
	once.Do(func() {
		instance = &CollectionHandler{
			clients: connection.GetClients(),
		}
	})

	return instance
}

func (f *CollectionHandler) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var payload mdbDto.Payload

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

	if payload.Command == 1 {
		task.AddResult(
			collection.InsertOne(
				ctx,
				message,
				&payload,
			),
		)
	} else if payload.Command == 2 {
		task.AddResult(
			collection.BulkWrite(
				ctx,
				message,
				&payload,
			),
		)
	} else if payload.Command == 3 {
		task.AddResult(
			collection.Aggregate(
				ctx,
				task,
				message,
				&payload,
			),
		)
	} else if payload.Command == 4 {
		task.AddResult(
			collection.InsertMany(
				ctx,
				message,
				&payload,
			),
		)
	} else if payload.Command == 5 {
		task.AddResult(
			collection.CountDocuments(
				ctx,
				message,
				&payload,
			),
		)
	} else if payload.Command == 6 {
		task.AddResult(
			collection.UpdateOne(
				ctx,
				message,
				&payload,
			),
		)
	} else if payload.Command == 7 {
		task.AddResult(
			collection.FindOne(
				ctx,
				message,
				&payload,
			),
		)
	} else if payload.Command == 8 {
		task.AddResult(
			collection.CreateIndex(
				ctx,
				message,
				&payload,
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
