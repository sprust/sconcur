package mongodb_feature

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/features/mongodb_feature/connections"
	"sconcur/internal/features/mongodb_feature/helpers"
	"sconcur/internal/tasks"
	"strconv"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
)

const resultKey = "_result"

var _ contracts.MessageHandler = (*Feature)(nil)

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

	slog.Debug(
		fmt.Sprintf(
			"mongodb: received message: %+v",
			message,
		))

	err := json.Unmarshal([]byte(message.Payload), &payload)

	if err != nil {
		task.AddResult(
			&dto.Result{
				Method:   message.Method,
				TaskKey:  message.TaskKey,
				Waitable: true,
				IsError:  true,
				Payload: fmt.Sprintf(
					"mongodb: parse payload error: %s",
					err.Error(),
				),
			},
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
			&dto.Result{
				Method:   message.Method,
				TaskKey:  message.TaskKey,
				Waitable: true,
				IsError:  true,
				Payload: fmt.Sprintf(
					"mongodb: %s",
					err.Error(),
				),
			},
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

		return
	}

	if payload.Command == 2 {
		task.AddResult(
			f.bulkWrite(
				ctx,
				message,
				&payload,
				collection,
			),
		)

		return
	}

	if payload.Command == 3 {
		task.AddResult(
			f.aggregate(
				ctx,
				task,
				message,
				&payload,
				collection,
			),
		)

		return
	}

	if payload.Command == 4 {
		task.AddResult(
			f.insertMany(
				ctx,
				message,
				&payload,
				collection,
			),
		)

		return
	}

	if payload.Command == 5 {
		task.AddResult(
			f.count(
				ctx,
				message,
				&payload,
				collection,
			),
		)

		return
	}

	task.AddResult(
		&dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload:  "mongodb: unknow command",
		},
	)
}

func (f *Feature) insertOne(
	ctx context.Context,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	doc, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: parse payload data error: %s",
				err.Error(),
			),
		}
	}

	result, err := collection.InsertOne(ctx, doc)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: insertOne error: %s",
				err.Error(),
			),
		}
	}

	serializedResult, err := helpers.MarshalResult(result)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: marshal insertOne result error: %s",
				err.Error(),
			),
		}
	}

	return &dto.Result{
		Method:   message.Method,
		TaskKey:  message.TaskKey,
		Waitable: true,
		IsError:  false,
		Payload:  serializedResult,
	}
}

func (f *Feature) bulkWrite(
	ctx context.Context,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	models, err := helpers.UnmarshalModels(payload.Data)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: parse bulkWrite payload data error: %s",
				err.Error(),
			),
		}
	}

	result, err := collection.BulkWrite(ctx, models)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: bulkWrite error: %s",
				err.Error(),
			),
		}
	}

	serializedResult, err := helpers.MarshalResult(result)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: marshal bulkWrite result error: %s",
				err.Error(),
			),
		}
	}

	return &dto.Result{
		Method:   message.Method,
		TaskKey:  message.TaskKey,
		Waitable: true,
		IsError:  false,
		Payload:  serializedResult,
	}
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
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: parse aggregate payload data error: %s",
				err.Error(),
			),
		}
	}

	cursor, err := collection.Aggregate(ctx, pipeline)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: aggregate error: %s",
				err.Error(),
			),
		}
	}

	maxBatchCount := 20

	var items []interface{}

	var response string

	for cursor.Next(ctx) {
		if err := cursor.Err(); err != nil {
			_ = cursor.Close(ctx)

			return &dto.Result{
				Method:   message.Method,
				TaskKey:  message.TaskKey,
				Waitable: true,
				IsError:  true,
				Payload: fmt.Sprintf(
					"mongodb: aggregate cursor error: %s",
					err.Error(),
				),
			}
		}

		items = append(items, cursor.Current)

		if len(items) == maxBatchCount {
			response, err = helpers.MarshalResult(
				bson.D{
					{Key: resultKey, Value: items},
				},
			)

			if err != nil {
				return &dto.Result{
					Method:   message.Method,
					TaskKey:  message.TaskKey,
					Waitable: true,
					IsError:  true,
					Payload: fmt.Sprintf(
						"mongodb: result marshal error: %s",
						err.Error(),
					),
				}
			}

			task.AddResult(
				&dto.Result{
					Method:   message.Method,
					TaskKey:  message.TaskKey,
					Waitable: true,
					IsError:  false,
					Payload:  response,
					HasNext:  true,
				},
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
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: result marshal error: %s",
				err.Error(),
			),
		}
	}

	return &dto.Result{
		Method:   message.Method,
		TaskKey:  message.TaskKey,
		Waitable: true,
		IsError:  false,
		Payload:  response,
	}
}

func (f *Feature) insertMany(
	ctx context.Context,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	docs, err := helpers.UnmarshalDocuments(payload.Data)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: parse payload data error: %s",
				err.Error(),
			),
		}
	}

	result, err := collection.InsertMany(ctx, docs)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: insertOne error: %s",
				err.Error(),
			),
		}
	}

	serializedResult, err := helpers.MarshalResult(result)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: marshal insertOne result error: %s",
				err.Error(),
			),
		}
	}

	return &dto.Result{
		Method:   message.Method,
		TaskKey:  message.TaskKey,
		Waitable: true,
		IsError:  false,
		Payload:  serializedResult,
	}
}

func (f *Feature) count(
	ctx context.Context,
	message *dto.Message,
	payload *Payload,
	collection *mongo.Collection,
) *dto.Result {
	filter, err := helpers.UnmarshalDocument(payload.Data)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: parse payload data error: %s",
				err.Error(),
			),
		}
	}

	result, err := collection.CountDocuments(ctx, filter)

	if err != nil {
		return &dto.Result{
			Method:   message.Method,
			TaskKey:  message.TaskKey,
			Waitable: true,
			IsError:  true,
			Payload: fmt.Sprintf(
				"mongodb: count error: %s",
				err.Error(),
			),
		}
	}

	return &dto.Result{
		Method:   message.Method,
		TaskKey:  message.TaskKey,
		Waitable: true,
		IsError:  false,
		Payload:  strconv.FormatInt(result, 10),
	}
}
