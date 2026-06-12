package find_state

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

type FindState struct {
	ctx         context.Context
	message     *dto.Message
	mCollection *mongo.Collection
	filter      interface{}
	opts        *options.FindOptions
	batchSize   int
	errFactory  *errs.Factory
	cursor      *mongo.Cursor
	pending     bson.Raw
	startTime   time.Time
}

func New(
	ctx context.Context,
	message *dto.Message,
	mCollection *mongo.Collection,
	filter interface{},
	opts *options.FindOptions,
	batchSize int,
	errFactory *errs.Factory,
) contracts.StateContract {
	return &FindState{
		ctx:         ctx,
		message:     message,
		mCollection: mCollection,
		filter:      filter,
		opts:        opts,
		batchSize:   batchSize,
		errFactory:  errFactory,
		startTime:   time.Now(),
	}
}

func (s *FindState) Next() *dto.Result {
	if s.cursor == nil {
		s.startTime = time.Now()

		cursor, err := s.mCollection.Find(s.ctx, s.filter, s.opts)

		if err != nil {
			return dto.NewErrorResult(
				s.message,
				s.errFactory.ByErr("find error", err),
			)
		}

		s.cursor = cursor
	}

	itemsCapacity := 0

	if s.batchSize > 0 {
		itemsCapacity = s.batchSize
	}

	if len(s.pending) > 0 {
		itemsCapacity++
	}

	items := make([]bson.Raw, 0, itemsCapacity)

	if len(s.pending) > 0 {
		items = append(items, s.pending)
		s.pending = nil
	}

	for {
		for s.batchSize <= 0 || len(items) < s.batchSize {
			if !s.cursor.Next(s.ctx) {
				if err := s.cursor.Err(); err != nil {
					_ = s.cursor.Close(s.ctx)

					return dto.NewErrorResult(
						s.message,
						s.errFactory.ByErr("find cursor error", err),
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
					s.errFactory.ByErr("find cursor error", err),
				)
			}

			return s.finish(items)
		}

		s.pending = cloneRaw(s.cursor.Current)

		response, err := serializer.MarshalDocumentBatchRaw(items)

		if err != nil {
			return dto.NewErrorResult(
				s.message,
				s.errFactory.ByErr("find result marshal error", err),
			)
		}

		return dto.NewSuccessResultWithNext(s.message, response, s.calcExecutionMs())
	}
}

func (s *FindState) calcExecutionMs() int {
	return helpers.CalcExecutionMs(s.startTime)
}

func (s *FindState) finish(items []bson.Raw) *dto.Result {
	response, err := serializer.MarshalDocumentBatchRaw(items)

	_ = s.cursor.Close(s.ctx)

	if err != nil {
		return dto.NewErrorResult(
			s.message,
			s.errFactory.ByErr("find result marshal error", err),
		)
	}

	return dto.NewSuccessResult(s.message, response, s.calcExecutionMs())
}

func cloneRaw(raw bson.Raw) bson.Raw {
	return append(bson.Raw(nil), raw...)
}
