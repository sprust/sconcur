package aggregation_state

import (
	"context"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mongodb/serializer"
	"sconcur/internal/helpers"
	"sync"
	"time"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

const closeCursorTimeout = 5 * time.Second

type AggregationState struct {
	// mutex serializes Next against Close: Close may fire from the task
	// context cancellation while a Next call is still using the cursor.
	mutex       sync.Mutex
	ctx         context.Context
	message     *dto.Message
	mCollection *mongo.Collection
	pipeline    interface{}
	batchSize   int
	errFactory  *errs.Factory
	release     func()
	cursor      *mongo.Cursor
	pending     bson.Raw
	startTime   time.Time
}

func New(
	ctx context.Context,
	message *dto.Message,
	mCollection *mongo.Collection,
	pipeline interface{},
	batchSize int,
	errFactory *errs.Factory,
	release func(),
) contracts.StateContract {
	return &AggregationState{
		ctx:         ctx,
		message:     message,
		mCollection: mCollection,
		pipeline:    pipeline,
		batchSize:   batchSize,
		errFactory:  errFactory,
		release:     release,
		startTime:   time.Now(),
	}
}

func (s *AggregationState) marshalBatch(items []bson.Raw) (string, error) {
	return serializer.MarshalDocumentBatchRaw(items)
}

func (s *AggregationState) Close() {
	s.mutex.Lock()

	s.closeCursorLocked()

	release := s.release
	s.release = nil

	s.mutex.Unlock()

	// Release the client owner outside the lock; runs once per state.
	if release != nil {
		release()
	}
}

// closeCursorLocked uses a fresh context: the task context may already be
// cancelled, and killCursors would never reach the server through it.
func (s *AggregationState) closeCursorLocked() {
	if s.cursor == nil {
		return
	}

	closeCtx, closeCtxCancel := context.WithTimeout(context.Background(), closeCursorTimeout)
	defer closeCtxCancel()

	_ = s.cursor.Close(closeCtx)

	s.cursor = nil
}

func (s *AggregationState) Next() *dto.Result {
	s.mutex.Lock()
	defer s.mutex.Unlock()

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
					s.closeCursorLocked()

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
				s.closeCursorLocked()

				return dto.NewErrorResult(
					s.message,
					s.errFactory.ByErr("aggregate cursor error", err),
				)
			}

			return s.finish(items)
		}

		s.pending = cloneRaw(s.cursor.Current)

		response, err := s.marshalBatch(items)

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

func (s *AggregationState) finish(items []bson.Raw) *dto.Result {
	response, err := s.marshalBatch(items)

	s.closeCursorLocked()

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
