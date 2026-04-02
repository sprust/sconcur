package mysql_feature

import (
	"context"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/mysql/connection"
	"sconcur/internal/features/mysql/objects"
	"sconcur/internal/features/mysql/transactions"
	"sconcur/internal/helpers"
	"sconcur/internal/tasks"
	"strings"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

var _ contracts.FeatureContract = (*MysqlFeature)(nil)

var once sync.Once
var instance *MysqlFeature

var errFactory = errs.NewErrorsFactory("mysql")

type MysqlFeature struct {
	clients *connection.Clients
	store   *transactions.Store
}

func Get() *MysqlFeature {
	once.Do(func() {
		instance = &MysqlFeature{
			clients: connection.GetClients(),
			store:   transactions.GetStore(),
		}
	})

	return instance
}

func (f *MysqlFeature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var payload objects.Payload

	if err := json.Unmarshal([]byte(message.Payload), &payload); err != nil {
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByErr("parse payload error", err),
			),
		)

		return
	}

	ctx := task.GetContext()

	switch payload.Command {
	case 1:
		task.AddResult(
			f.handleQuery(ctx, message, &payload),
		)
	case 2:
		task.AddResult(
			f.handleExec(ctx, message, &payload),
		)
	case 3:
		task.AddResult(
			f.handleBegin(ctx, message, &payload),
		)
	case 4:
		task.AddResult(
			f.handleCommit(ctx, message, &payload),
		)
	case 5:
		task.AddResult(
			f.handleRollback(ctx, message, &payload),
		)
	default:
		task.AddResult(
			dto.NewErrorResult(
				message,
				errFactory.ByText("unknown command"),
			),
		)
	}
}

func (f *MysqlFeature) handleQuery(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	queryCtx, cancel := withTimeout(ctx, payload.TimeoutMs)

	if cancel != nil {
		defer cancel()
	}

	start := time.Now()
	rows, err := f.query(queryCtx, payload)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("query error", err),
		)
	}

	defer func(rows *sql.Rows) {
		_ = rows.Close()
	}(rows)

	cols, data, err := rowsToResult(rows)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("result error", err),
		)
	}

	resultPayload, err := json.Marshal(map[string]interface{}{
		"cols": cols,
		"rows": data,
	})

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal result error", err),
		)
	}

	return dto.NewSuccessResult(message, string(resultPayload), executionMs)
}

func (f *MysqlFeature) handleExec(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	queryCtx, cancel := withTimeout(ctx, payload.TimeoutMs)

	if cancel != nil {
		defer cancel()
	}

	start := time.Now()
	result, err := f.exec(queryCtx, payload)
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("exec error", err),
		)
	}

	rowsAffected, _ := result.RowsAffected()
	lastInsertId, _ := result.LastInsertId()

	resultPayload, err := json.Marshal(map[string]interface{}{
		"rowsAffected": rowsAffected,
		"lastInsertId": lastInsertId,
	})

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal result error", err),
		)
	}

	return dto.NewSuccessResult(message, string(resultPayload), executionMs)
}

func (f *MysqlFeature) handleBegin(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	start := time.Now()

	if payload.Dsn == "" {
		return dto.NewErrorResult(
			message,
			errFactory.ByText("dsn is required"),
		)
	}

	queryCtx, cancel := withTimeout(ctx, payload.TimeoutMs)

	if cancel != nil {
		defer cancel()
	}

	db, err := f.clients.GetClient(payload.Dsn)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("client", err),
		)
	}

	tx, err := db.BeginTx(queryCtx, &sql.TxOptions{
		Isolation: sql.IsolationLevel(payload.Isolation),
	})

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("begin", err),
		)
	}

	txKey := f.store.New(message.FlowKey, tx)

	return dto.NewSuccessResult(message, txKey, helpers.CalcExecutionMs(start))
}

func (f *MysqlFeature) handleCommit(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	start := time.Now()

	tx, ok := f.store.Get(payload.TxKey)

	if !ok {
		return dto.NewErrorResult(
			message,
			errFactory.ByText("transaction not found"),
		)
	}

	queryCtx, cancel := withTimeout(ctx, payload.TimeoutMs)

	if cancel != nil {
		defer cancel()
	}

	select {
	case <-queryCtx.Done():
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("commit", queryCtx.Err()),
		)
	default:
	}

	if err := tx.Commit(); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("commit", err),
		)
	}

	f.store.Delete(payload.TxKey)

	return dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(start))
}

func (f *MysqlFeature) handleRollback(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	start := time.Now()

	tx, ok := f.store.Get(payload.TxKey)

	if !ok {
		return dto.NewErrorResult(
			message,
			errFactory.ByText("transaction not found"),
		)
	}

	queryCtx, cancel := withTimeout(ctx, payload.TimeoutMs)

	if cancel != nil {
		defer cancel()
	}

	select {
	case <-queryCtx.Done():
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("rollback", queryCtx.Err()),
		)
	default:
	}

	if err := tx.Rollback(); err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("rollback", err),
		)
	}

	f.store.Delete(payload.TxKey)

	return dto.NewSuccessResult(message, "", helpers.CalcExecutionMs(start))
}

func (f *MysqlFeature) query(ctx context.Context, payload *objects.Payload) (*sql.Rows, error) {
	args, err := decodeBindings(payload.Bindings)

	if err != nil {
		return nil, err
	}

	if payload.TxKey != "" {
		tx, ok := f.store.Get(payload.TxKey)

		if !ok {
			return nil, errors.New("transaction not found")
		}

		return tx.QueryContext(ctx, payload.Sql, args...)
	}

	if payload.Dsn == "" {
		return nil, errors.New("dsn is required")
	}

	db, err := f.clients.GetClient(payload.Dsn)

	if err != nil {
		return nil, err
	}

	return db.QueryContext(ctx, payload.Sql, args...)
}

func (f *MysqlFeature) exec(ctx context.Context, payload *objects.Payload) (sql.Result, error) {
	args, err := decodeBindings(payload.Bindings)

	if err != nil {
		return nil, err
	}

	if payload.TxKey != "" {
		tx, ok := f.store.Get(payload.TxKey)

		if !ok {
			return nil, errors.New("transaction not found")
		}

		return tx.ExecContext(ctx, payload.Sql, args...)
	}

	if payload.Dsn == "" {
		return nil, errors.New("dsn is required")
	}

	db, err := f.clients.GetClient(payload.Dsn)

	if err != nil {
		return nil, err
	}

	return db.ExecContext(ctx, payload.Sql, args...)
}

func withTimeout(ctx context.Context, timeoutMs int) (context.Context, context.CancelFunc) {
	if timeoutMs <= 0 {
		return ctx, nil
	}

	return context.WithTimeout(ctx, time.Duration(timeoutMs)*time.Millisecond)
}

func decodeBindings(payload string) ([]interface{}, error) {
	if payload == "" {
		return nil, nil
	}

	var raw []interface{}

	if err := json.Unmarshal([]byte(payload), &raw); err != nil {
		return nil, err
	}

	bindings := make([]interface{}, len(raw))

	for i, value := range raw {
		converted, err := normalizeBinding(value)

		if err != nil {
			return nil, err
		}

		bindings[i] = converted
	}

	return bindings, nil
}

func normalizeBinding(value interface{}) (interface{}, error) {
	switch v := value.(type) {
	case nil:
		return nil, nil
	case string:
		if strings.HasPrefix(v, "$bin-ldkf:") {
			encodedData := v[len("$bin-ldkf:"):]

			decodedData, err := base64.StdEncoding.DecodeString(encodedData)

			if err != nil {
				return nil, fmt.Errorf("failed to decode base64 binary data: %w", err)
			}

			return decodedData, nil
		}

		return v, nil
	case bool:
		return v, nil
	case float64:
		if float64(int64(v)) == v {
			return int64(v), nil
		}
		return v, nil
	default:
		return nil, fmt.Errorf("unsupported binding type: %T", value)
	}
}

func rowsToResult(rows *sql.Rows) ([]string, [][]interface{}, error) {
	cols, err := rows.Columns()

	if err != nil {
		return nil, nil, err
	}

	result := make([][]interface{}, 0)

	for rows.Next() {
		row, err := scanRow(rows, len(cols))

		if err != nil {
			return nil, nil, err
		}

		result = append(result, row)
	}

	if err := rows.Err(); err != nil {
		return nil, nil, err
	}

	return cols, result, nil
}

func scanRow(rows *sql.Rows, size int) ([]interface{}, error) {
	values := make([]interface{}, size)
	scanTargets := make([]interface{}, size)

	for i := range values {
		var raw sql.RawBytes
		scanTargets[i] = &raw
	}

	if err := rows.Scan(scanTargets...); err != nil {
		return nil, err
	}

	for i, target := range scanTargets {
		raw := *(target.(*sql.RawBytes))

		if raw == nil {
			values[i] = nil
		} else {
			values[i] = string(raw)
		}
	}

	return values, nil
}
