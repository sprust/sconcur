package sql_feature

import (
	"context"
	"database/sql"
	"sconcur/internal/dto"
	"sconcur/internal/features/sql/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sync"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// pendingTransactions maps a transaction id (the begin task key) to its live
// session, so a query/exec/commit/rollback command arriving on its own task finds
// the pinned *sql.Tx. Mirrors the HTTP-client's pendingUploads.
var pendingTransactions sync.Map

// transactionSession ties a *sql.Tx to its pooled connection. finalize runs exactly
// once (commit, rollback, or the holder's cleanup), so the pool owner is released a
// single time regardless of which path ends the transaction.
type transactionSession struct {
	tx       *sql.Tx
	pool     *pool
	pools    *pools
	id       string
	finalize sync.Once
}

func (s *transactionSession) commit() error {
	var err error

	s.finalize.Do(func() {
		err = s.tx.Commit()

		s.cleanup()
	})

	return err
}

func (s *transactionSession) rollback() error {
	var err error

	s.finalize.Do(func() {
		err = s.tx.Rollback()

		s.cleanup()
	})

	return err
}

func (s *transactionSession) cleanup() {
	pendingTransactions.Delete(s.id)

	s.pools.release(s.pool)
}

// transactionHolderState keeps the begin task alive (registered with hasNext) so the
// pinned connection survives across the transaction's commands. Its Next is the
// release marker pulled by PHP after commit/rollback; Close rolls back as a safety
// net (a no-op once the transaction was already finalized).
type transactionHolderState struct {
	session   *transactionSession
	message   *dto.Message
	startTime time.Time
}

func (h *transactionHolderState) Next() *dto.Result {
	return dto.NewSuccessResult(h.message, "", helpers.CalcExecutionMs(h.startTime))
}

func (h *transactionHolderState) Close() {
	_ = h.session.rollback()
}

// handleBegin opens a transaction on a pooled connection and registers the holder
// state. The result carries hasNext so the begin task's context stays alive for the
// whole transaction; when that context is cancelled (flow stop), database/sql rolls
// the transaction back and the holder's Close releases the pool.
func (f *SqlFeature) handleBegin(task *tasks.Task, envelope *payloads.Envelope) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.BeginParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse begin params", err)))

		return
	}

	acquired, err := f.pools.acquire(
		f.driverName,
		envelope.Dsn,
		envelope.MaxOpenConns,
		envelope.MaxIdleConns,
		envelope.ConnMaxLifetimeMs,
	)

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("connect", err)))

		return
	}

	// The transaction lives across many tasks, so it is bound to the long-lived
	// begin task context (kept alive by hasNext), not to a per-statement deadline.
	ctx := task.GetContext()

	transaction, err := acquired.db.BeginTx(ctx, &sql.TxOptions{
		Isolation: sql.IsolationLevel(params.IsolationLevel),
		ReadOnly:  params.ReadOnly,
	})

	if err != nil {
		f.pools.release(acquired)

		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("begin", err)))

		return
	}

	transactionId := message.TaskKey

	session := &transactionSession{
		tx:    transaction,
		pool:  acquired,
		pools: f.pools,
		id:    transactionId,
	}

	if _, loaded := pendingTransactions.LoadOrStore(transactionId, session); loaded {
		_ = transaction.Rollback()

		f.pools.release(acquired)

		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByText("duplicate transaction "+transactionId)))

		return
	}

	holder := &transactionHolderState{
		session:   session,
		message:   message,
		startTime: startTime,
	}

	if err := states.Get().Register(message.TaskKey, holder); err != nil {
		_ = session.rollback()

		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("register transaction", err)))

		return
	}

	// On flow stop / parent cancellation: drop the holder, which rolls back (if not
	// already finalized) and releases the pool. The begin context cancellation also
	// makes database/sql roll the transaction back on its own.
	context.AfterFunc(ctx, func() {
		states.Get().DeleteState(transactionId)
	})

	task.AddResult(dto.NewSuccessResultWithNext(message, "", helpers.CalcExecutionMs(startTime)))
}

// handleFinalize commits or rolls back the transaction named by the command, then
// PHP releases the holder via next(). finalize is idempotent, so a stop that races
// the explicit call cannot double-release the pool.
func (f *SqlFeature) handleFinalize(task *tasks.Task, envelope *payloads.Envelope, isCommit bool) {
	message := task.GetMessage()
	startTime := time.Now()

	var params payloads.TransactionRefParams

	if err := msgpack.Unmarshal(envelope.Data, &params); err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("parse transaction ref", err)))

		return
	}

	value, ok := pendingTransactions.Load(params.TransactionId)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByText("unknown transaction "+params.TransactionId)))

		return
	}

	session, ok := value.(*transactionSession)

	if !ok {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByText("bad transaction session")))

		return
	}

	var err error

	if isCommit {
		err = session.commit()
	} else {
		err = session.rollback()
	}

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, f.errFactory.ByErr("finalize transaction", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(startTime)))
}

// loadTransaction returns the live *sql.Tx for a query/exec carrying a transaction
// id, or an error string if it is not found.
func (f *SqlFeature) loadTransaction(transactionId string) (*sql.Tx, string) {
	value, ok := pendingTransactions.Load(transactionId)

	if !ok {
		return nil, f.errFactory.ByText("unknown transaction " + transactionId)
	}

	session, ok := value.(*transactionSession)

	if !ok {
		return nil, f.errFactory.ByText("bad transaction session")
	}

	return session.tx, ""
}
