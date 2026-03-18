package aggregation_state

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

type AggregationState struct {
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

func New(
	ctx context.Context,
	message *dto.Message,
	mCollection *mongo.Collection,
	pipeline interface{},
	batchSize int,
	resultKey string,
	errFactory *errs.Factory,
) contracts.StateContract {
	return &AggregationState{
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

func (s *AggregationState) Next() *dto.Result {
	if s.cursor == nil {
		s.startTime = time.Now()

		var opts *options.AggregateOptions

		if s.batchSize > 0 {
			opts = options.Aggregate().SetBatchSize(int32(s.batchSize))
		}

		cursor, err := s.mCollection.Aggregate(s.ctx, s.pipeline, opts)

		if err != nil {
			return dto.NewErrorResult(
				s.message,
				s.errFactory.ByErr("aggregate error", err),
			)
		}

		s.cursor = cursor
	}

	var items []interface{}

	if s.batchSize > 0 {
		items = make([]interface{}, 0, s.batchSize)
	}

	for s.cursor.Next(s.ctx) {
		items = append(items, s.cursor.Current)

		if len(items) == s.batchSize {
			response, err := serializer.MarshalDocument(
				bson.D{
					{Key: s.resultKey, Value: items},
				},
			)

			if err != nil {
				return dto.NewErrorResult(
					s.message,
					s.errFactory.ByErr("aggregate result marshal error", err),
				)
			}

			return dto.NewSuccessResultWithNext(s.message, response, s.calcExecutionMs())
		}
	}

	if err := s.cursor.Err(); err != nil {
		_ = s.cursor.Close(s.ctx)

		return dto.NewErrorResult(
			s.message,
			s.errFactory.ByErr("aggregate cursor error", err),
		)
	}

	response, err := serializer.MarshalDocument(
		bson.D{
			{Key: s.resultKey, Value: items},
		},
	)

	_ = s.cursor.Close(s.ctx)

	if err != nil {
		return dto.NewErrorResult(
			s.message,
			s.errFactory.ByErr("aggregate result marshal error", err),
		)
	}

	return dto.NewSuccessResult(s.message, response, s.calcExecutionMs())
}

func (s *AggregationState) calcExecutionMs() int {
	return helpers.CalcExecutionMs(s.startTime)
}
