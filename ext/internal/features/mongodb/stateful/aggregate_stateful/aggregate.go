package aggregate_stateful

import (
	"context"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/helpers"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
)

type AggregateState struct {
	ctx         context.Context
	message     *dto.Message
	mCollection *mongo.Collection
	pipeline    interface{}
	batchSize   int
	resultKey   string
	errFactory  *errs.Factory
	cursor      *mongo.Cursor
}

func NewAggregateState(
	ctx context.Context,
	message *dto.Message,
	mCollection *mongo.Collection,
	pipeline interface{},
	batchSize int,
	resultKey string,
	errFactory *errs.Factory,
) *AggregateState {
	return &AggregateState{
		ctx:         ctx,
		message:     message,
		mCollection: mCollection,
		pipeline:    pipeline,
		batchSize:   batchSize,
		resultKey:   resultKey,
		errFactory:  errFactory,
	}
}

func (a *AggregateState) Next() *dto.Result {
	if a.cursor == nil {
		cursor, err := a.mCollection.Aggregate(a.ctx, a.pipeline)

		if err != nil {
			return dto.NewErrorResult(
				a.message,
				a.errFactory.ByErr("aggregate error", err),
			)
		}

		a.cursor = cursor
	}

	var items []interface{}

	for a.cursor.Next(a.ctx) {
		if err := a.cursor.Err(); err != nil {
			_ = a.cursor.Close(a.ctx)

			return dto.NewErrorResult(
				a.message,
				a.errFactory.ByErr("aggregate cursor error", err),
			)
		}

		items = append(items, a.cursor.Current)

		if len(items) == a.batchSize {
			response, err := helpers.MarshalDocument(
				bson.D{
					{Key: a.resultKey, Value: items},
				},
			)

			if err != nil {
				return dto.NewErrorResult(
					a.message,
					a.errFactory.ByErr("aggregate result marshal error", err),
				)
			}

			return dto.NewSuccessResultWithNext(a.message, response)
		}
	}

	response, err := helpers.MarshalDocument(
		bson.D{
			{Key: a.resultKey, Value: items},
		},
	)

	if err != nil {
		return dto.NewErrorResult(
			a.message,
			a.errFactory.ByErr("aggregate result marshal error", err),
		)
	}

	return dto.NewSuccessResult(a.message, response)
}
