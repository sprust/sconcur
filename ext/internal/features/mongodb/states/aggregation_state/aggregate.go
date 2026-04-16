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
	errFactory  *errs.Factory
	cursor      *mongo.Cursor
	pending     []interface{}
	startTime   time.Time
}

func New(
	ctx context.Context,
	message *dto.Message,
	mCollection *mongo.Collection,
	pipeline interface{},
	batchSize int,
	_ string,
	errFactory *errs.Factory,
) contracts.StateContract {
	return &AggregationState{
		ctx:         ctx,
		message:     message,
		mCollection: mCollection,
		pipeline:    pipeline,
		batchSize:   batchSize,
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

	itemsCapacity := 0

	if s.batchSize > 0 {
		itemsCapacity = s.batchSize
	}

	items := make([]interface{}, 0, itemsCapacity)

	if len(s.pending) > 0 {
		items = append(items, s.pending...)
		s.pending = nil
	}

	for {
		for s.batchSize <= 0 || len(items) < s.batchSize {
			if !s.cursor.Next(s.ctx) {
				if err := s.cursor.Err(); err != nil {
					_ = s.cursor.Close(s.ctx)

					return dto.NewErrorResult(
						s.message,
						s.errFactory.ByErr("aggregate cursor error", err),
					)
				}

				return s.finish(items)
			}

			items = append(items, cloneRaw(s.cursor.Current))
		}

		if !s.cursor.Next(s.ctx) {
			if err := s.cursor.Err(); err != nil {
				_ = s.cursor.Close(s.ctx)

				return dto.NewErrorResult(
					s.message,
					s.errFactory.ByErr("aggregate cursor error", err),
				)
			}

			return s.finish(items)
		}

		s.pending = []interface{}{cloneRaw(s.cursor.Current)}

		response, err := serializer.MarshalDocumentBatch(items)

		if err != nil {
			return dto.NewErrorResult(
				s.message,
				s.errFactory.ByErr("aggregate result marshal error", err),
			)
		}

		return dto.NewSuccessResultWithNext(s.message, response, s.calcExecutionMs())
	}
}

func (s *AggregationState) calcExecutionMs() int {
	return helpers.CalcExecutionMs(s.startTime)
}

func (s *AggregationState) finish(items []interface{}) *dto.Result {
	response, err := serializer.MarshalDocumentBatch(items)

	_ = s.cursor.Close(s.ctx)

	if err != nil {
		return dto.NewErrorResult(
			s.message,
			s.errFactory.ByErr("aggregate result marshal error", err),
		)
	}

	return dto.NewSuccessResult(s.message, response, s.calcExecutionMs())
}

func cloneRaw(raw bson.Raw) bson.Raw {
	return append(bson.Raw(nil), raw...)
}
