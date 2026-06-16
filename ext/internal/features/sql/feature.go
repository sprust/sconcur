package sql_feature

import (
	"context"
	"database/sql"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/sql/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*SqlFeature)(nil)

// SqlFeature handles SQL commands for one driver (mysql now, pgx later) on top of
// database/sql. The whole feature is driver-agnostic; the driver name selects the
// registered database/sql driver and prefixes error messages. Created per driver
// via the driver-specific Get* constructor (see drivers_mysql.go).
type SqlFeature struct {
	driverName string
	errFactory *errs.Factory
	pools      *pools
}

func newSqlFeature(driverName string) *SqlFeature {
	return &SqlFeature{
		driverName: driverName,
		errFactory: errs.NewErrorsFactory(driverName),
		pools:      getPools(),
	}
}

// CloseAllPools closes every open database/sql pool. Called on extension shutdown.
func CloseAllPools() {
	getPools().closeAll()
}

func (f *SqlFeature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var envelope payloads.Envelope

	if err := msgpack.Unmarshal(message.Payload, &envelope); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse envelope", err)))

		return
	}

	switch types.SqlCommand(envelope.Command) {
	case types.SqlQuery:
		f.handleQuery(task, &envelope)
	case types.SqlExec:
		f.handleExec(task, &envelope)
	case types.SqlBegin:
		f.handleBegin(task, &envelope)
	case types.SqlCommit:
		f.handleFinalize(task, &envelope, true)
	case types.SqlRollback:
		f.handleFinalize(task, &envelope, false)
	default:
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByText("unknown command")))
	}
}

// handleQuery streams a SELECT result. Autocommit queries acquire a pooled
// connection released on the cursor's Close; transaction queries run on the pinned
// *sql.Tx and leave its connection to the begin task.
func (f *SqlFeature) handleQuery(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()

	var params payloads.QueryParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse query params", err)))

		return
	}

	bindings := normalizeBindings(params.Bindings)

	// The cursor state outlives Handle (it is pulled via next), so the deadline's
	// cancel cannot be deferred — it is carried into the state and called on Close.
	ctx := task.GetContext()

	var cancel context.CancelFunc

	if envelope.TimeoutMs > 0 {
		ctx, cancel = context.WithTimeout(ctx, time.Duration(envelope.TimeoutMs)*time.Millisecond)
	}

	var open func(context.Context) (*sql.Rows, error)
	var release func()

	if params.TransactionId != "" {
		transaction, errText := f.loadTransaction(params.TransactionId)

		if errText != "" {
			if cancel != nil {
				cancel()
			}

			task.AddResult(dto.NewErrorResult(message, errText))

			return
		}

		open = func(queryCtx context.Context) (*sql.Rows, error) {
			return transaction.QueryContext(queryCtx, params.Sql, bindings...)
		}
	} else {
		acquired, err := f.pools.acquire(
			f.driverName,
			envelope.Dsn,
			envelope.MaxOpenConns,
			envelope.MaxIdleConns,
			envelope.ConnMaxLifetimeMs,
		)

		if err != nil {
			if cancel != nil {
				cancel()
			}

			task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("connect", err)))

			return
		}

		open = func(queryCtx context.Context) (*sql.Rows, error) {
			return acquired.db.QueryContext(queryCtx, params.Sql, bindings...)
		}

		release = func() {
			f.pools.release(acquired)
		}
	}

	state := &rowsState{
		message:    message,
		ctx:        ctx,
		cancel:     cancel,
		open:       open,
		release:    release,
		batchSize:  params.BatchSize,
		errFactory: f.errFactory,
	}

	result, err := states.Get().Start(ctx, message.TaskKey, state)

	if err != nil {
		state.Close()

		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("start query", err)))

		return
	}

	task.AddResult(result)
}

// handleExec runs a non-row statement and returns affected-rows and last-insert-id.
func (f *SqlFeature) handleExec(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.ExecParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse exec params", err)))

		return
	}

	ctx := task.GetContext()

	if envelope.TimeoutMs > 0 {
		var cancel context.CancelFunc

		ctx, cancel = context.WithTimeout(ctx, time.Duration(envelope.TimeoutMs)*time.Millisecond)

		defer cancel()
	}

	bindings := normalizeBindings(params.Bindings)

	var result sql.Result
	var err error

	if params.TransactionId != "" {
		transaction, errText := f.loadTransaction(params.TransactionId)

		if errText != "" {
			task.AddResult(dto.NewErrorResult(message, errText))

			return
		}

		result, err = transaction.ExecContext(ctx, params.Sql, bindings...)
	} else {
		acquired, acquireErr := f.pools.acquire(
			f.driverName,
			envelope.Dsn,
			envelope.MaxOpenConns,
			envelope.MaxIdleConns,
			envelope.ConnMaxLifetimeMs,
		)

		if acquireErr != nil {
			task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("connect", acquireErr)))

			return
		}

		defer f.pools.release(acquired)

		result, err = acquired.db.ExecContext(ctx, params.Sql, bindings...)
	}

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("exec error", err)))

		return
	}

	task.AddResult(f.execResult(message, result, startTime))
}

func (f *SqlFeature) execResult(message *dto.Message, result sql.Result, startTime time.Time) *dto.Result {
	affectedRows, _ := result.RowsAffected()
	lastInsertId, _ := result.LastInsertId()

	encoded, err := msgpack.Marshal(map[string]int64{
		"ar": affectedRows,
		"li": lastInsertId,
	})

	if err != nil {
		return dto.NewErrorResult(message, f.errFactory.ByErr("marshal exec result", err))
	}

	return dto.NewSuccessResult(message, string(encoded), helpers.CalcExecutionMs(startTime))
}
