package sql_feature

import (
	"context"
	"database/sql"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/helpers"
	"sync"
	"time"
)

// rowsState streams a SELECT result to PHP batch by batch. It mirrors the MongoDB
// find cursor state: the result rows are opened lazily on the first Next, read in
// batches of batchSize, and a one-row look-ahead decides whether more batches follow.
type rowsState struct {
	// mutex serializes Next against Close: Close may fire from the task context
	// cancellation while a Next call is still using the rows.
	mutex      sync.Mutex
	message    *dto.Message
	ctx        context.Context
	open       func(ctx context.Context) (*sql.Rows, error)
	release    func()
	batchSize  int
	errFactory *errs.Factory
	rows       *sql.Rows
	columns    []string
	pending    map[string]any
	hasPending bool
	startTime  time.Time
}

func (s *rowsState) Next() *dto.Result {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	if s.rows == nil {
		s.startTime = time.Now()

		rows, err := s.open(s.ctx)

		if err != nil {
			return dto.NewErrorResult(s.message, s.errFactory.ByErr("query error", err))
		}

		columns, err := rows.Columns()

		if err != nil {
			_ = rows.Close()

			return dto.NewErrorResult(s.message, s.errFactory.ByErr("columns error", err))
		}

		s.rows = rows
		s.columns = columns
	}

	itemsCapacity := s.batchSize

	if itemsCapacity < 0 {
		itemsCapacity = 0
	}

	if s.hasPending {
		itemsCapacity++
	}

	items := make([]map[string]any, 0, itemsCapacity)

	if s.hasPending {
		items = append(items, s.pending)
		s.pending = nil
		s.hasPending = false
	}

	for {
		for s.batchSize <= 0 || len(items) < s.batchSize {
			row, ok, errText := s.readRow()

			if errText != "" {
				return dto.NewErrorResult(s.message, errText)
			}

			if !ok {
				return s.finish(items)
			}

			items = append(items, row)
		}

		// Look one row ahead: if there is more, this batch is non-final and the
		// peeked row is stashed for the next call.
		row, ok, errText := s.readRow()

		if errText != "" {
			return dto.NewErrorResult(s.message, errText)
		}

		if !ok {
			return s.finish(items)
		}

		s.pending = row
		s.hasPending = true

		response, marshalErr := marshalBatch(items)

		if marshalErr != nil {
			return dto.NewErrorResult(s.message, s.errFactory.ByErr("marshal rows error", marshalErr))
		}

		return dto.NewSuccessResultWithNext(s.message, response, helpers.CalcExecutionMs(s.startTime))
	}
}

// readRow advances the cursor by one row. ok is false once the rows are exhausted;
// a non-empty error string is a read failure (the rows are closed on error).
func (s *rowsState) readRow() (map[string]any, bool, string) {
	if !s.rows.Next() {
		if err := s.rows.Err(); err != nil {
			s.closeRowsLocked()

			return nil, false, s.errFactory.ByErr("rows error", err)
		}

		return nil, false, ""
	}

	row, err := scanRow(s.rows, s.columns)

	if err != nil {
		s.closeRowsLocked()

		return nil, false, s.errFactory.ByErr("scan error", err)
	}

	return row, true, ""
}

func (s *rowsState) finish(items []map[string]any) *dto.Result {
	response, err := marshalBatch(items)

	s.closeRowsLocked()

	if err != nil {
		return dto.NewErrorResult(s.message, s.errFactory.ByErr("marshal rows error", err))
	}

	return dto.NewSuccessResult(s.message, response, helpers.CalcExecutionMs(s.startTime))
}

func (s *rowsState) Close() {
	s.mutex.Lock()

	s.closeRowsLocked()

	release := s.release
	s.release = nil

	s.mutex.Unlock()

	// Release the pooled connection owner outside the lock; runs once per state.
	// A transaction-bound query has no release (the begin task holds the pool).
	if release != nil {
		release()
	}
}

func (s *rowsState) closeRowsLocked() {
	if s.rows == nil {
		return
	}

	_ = s.rows.Close()

	s.rows = nil
}
