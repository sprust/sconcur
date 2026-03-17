package aggregate_stateful

import (
	"context"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/serializer"
	"sconcur/internal/helpers"
	"time"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
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
	startTime   time.Time
}

func NewAggregateState(
	ctx context.Context,
	message *dto.Message,
	mCollection *mongo.Collection,
	pipeline interface{},
	batchSize int,
	resultKey string,
	errFactory *errs.Factory,
) contracts.StateContract {
	return &AggregateState{
		ctx:         ctx,
		message:     message,
		mCollection: mCollection,
		pipeline:    pipeline,
		batchSize:   batchSize,
		resultKey:   resultKey,
		errFactory:  errFactory,
		startTime:   time.Now(),
	}
}

func (a *AggregateState) Next() *dto.Result {
	if a.cursor == nil {
		a.startTime = time.Now()

		var opts *options.AggregateOptions

		if a.batchSize > 0 {
			opts = options.Aggregate().SetBatchSize(int32(a.batchSize))
		}

		cursor, err := a.mCollection.Aggregate(a.ctx, a.pipeline, opts)

		if err != nil {
			return dto.NewErrorResult(
				a.message,
				a.errFactory.ByErr("aggregate error", err),
			)
		}

		a.cursor = cursor
	}

	var items []interface{}

	if a.batchSize > 0 {
		items = make([]interface{}, 0, a.batchSize)
	}

	for a.cursor.Next(a.ctx) {
		items = append(items, a.cursor.Current)

		if len(items) == a.batchSize {
			response, err := serializer.MarshalDocument(
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

			return dto.NewSuccessResultWithNext(a.message, response, a.calcExecutionMs())
		}
	}

	if err := a.cursor.Err(); err != nil {
		_ = a.cursor.Close(a.ctx)

		return dto.NewErrorResult(
			a.message,
			a.errFactory.ByErr("aggregate cursor error", err),
		)
	}

	response, err := serializer.MarshalDocument(
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

	_ = a.cursor.Close(a.ctx)

	return dto.NewSuccessResult(a.message, response, a.calcExecutionMs())
}

func (a *AggregateState) calcExecutionMs() int {
	return helpers.CalcExecutionMs(a.startTime)
}
