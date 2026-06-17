<?php

declare(strict_types=1);

namespace SConcur\Features\Sql;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Payloads\CommitPayload;
use SConcur\Features\Sql\Payloads\Dto\Connection as ConnectionDto;
use SConcur\Features\Sql\Payloads\ExecPayload;
use SConcur\Features\Sql\Payloads\QueryPayload;
use SConcur\Features\Sql\Payloads\RollbackPayload;
use SConcur\Features\Sql\Results\ExecResult;
use SConcur\Features\Sql\Results\RowsResult;
use SConcur\State;
use SConcur\Transport\PayloadInterface;

/**
 * A database transaction pinned to a single connection across a series of tasks.
 * Opened by Connection::begin(); every query/exec carries the transaction id so
 * the Go side routes it to the held connection.
 *
 * The Go side keeps the connection alive through a held "begin" task; commit() and
 * rollback() finalize the transaction and then release that task. If the
 * transaction is abandoned (no commit/rollback, an exception, a flow stop), the Go
 * side rolls it back automatically when the flow's context is cancelled.
 */
class Transaction
{
    protected bool $finished = false;

    public function __construct(
        protected MethodEnum $method,
        protected ConnectionDto $connection,
        protected string $transactionId,
    ) {
    }

    /**
     * @param list<mixed> $bindings
     */
    public function query(string $sql, array $bindings = [], int $batchSize = 50): RowsResult
    {
        return new RowsResult(
            payload: new QueryPayload(
                method: $this->method,
                connection: $this->connection,
                sql: $sql,
                bindings: $bindings,
                transactionId: $this->transactionId,
                batchSize: $batchSize,
            ),
        );
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $bindings = []): array
    {
        return iterator_to_array(
            $this->query(
                sql: $sql,
                bindings: $bindings,
            ),
            preserve_keys: false,
        );
    }

    /**
     * @param list<mixed> $bindings
     */
    public function exec(string $sql, array $bindings = []): ExecResult
    {
        $taskResult = FeatureExecutor::exec(
            payload: new ExecPayload(
                method: $this->method,
                connection: $this->connection,
                sql: $sql,
                bindings: $bindings,
                transactionId: $this->transactionId,
            ),
        );

        return ExecResult::fromPayload($taskResult->payload);
    }

    public function commit(): void
    {
        $this->finish(
            payload: new CommitPayload(
                method: $this->method,
                connection: $this->connection,
                transactionId: $this->transactionId,
            ),
        );
    }

    public function rollback(): void
    {
        $this->finish(
            payload: new RollbackPayload(
                method: $this->method,
                connection: $this->connection,
                transactionId: $this->transactionId,
            ),
        );
    }

    /**
     * Sends the commit/rollback command, then releases the held begin task so its
     * connection returns to the pool. Idempotent: a second call is a no-op.
     */
    protected function finish(PayloadInterface $payload): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        FeatureExecutor::exec(payload: $payload);

        FeatureExecutor::next(taskKey: $this->transactionId);
    }

    /**
     * Abandoned without commit/rollback (e.g. an exception unwound the scope):
     * release the held begin flow on the synchronous path so no task dangles. The
     * Go side rolls the transaction back from the cancelled context. No-op in async
     * mode and after an explicit commit/rollback.
     */
    public function __destruct()
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        State::releaseSyncTaskFlow($this->transactionId);
    }
}
