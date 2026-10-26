<?php

declare(strict_types=1);

namespace SConcur\Features\Sql;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Payloads\BeginPayload;
use SConcur\Features\Sql\Payloads\Dto\Connection as ConnectionDto;
use SConcur\Features\Sql\Payloads\ExecPayload;
use SConcur\Features\Sql\Payloads\QueryPayload;
use SConcur\Features\Sql\Results\ExecResult;
use SConcur\Features\Sql\Results\RowsResult;

/**
 * Shared SQL connection on top of the extension's SQL feature. A driver facade
 * (Mysql\Connection, Pgsql\Connection) extends this and supplies its MethodEnum;
 * everything else — queries, exec, transactions — is driver-agnostic.
 *
 * Placeholders are the driver's native dialect (`?` for MySQL, `$1` for PgSQL);
 * bindings are always a positional list, and the SQL itself is never rewritten.
 *
 * Each call runs in the extension while the calling coroutine suspends, so many
 * statements run at the same time. Outside a WaitGroup it works synchronously.
 */
abstract readonly class Connection
{
    protected ConnectionDto $connection;

    abstract protected function getMethod(): MethodEnum;

    public function __construct(
        public string $dsn,
        ?int $timeoutMs = null,
        ?int $maxOpenConns = null,
        ?int $maxIdleConns = null,
        ?int $connMaxLifetimeMs = null,
    ) {
        // maxIdleConns defaults to the cap. The core does not apply it: sqlx has
        // no cap on idle connections — it keeps them up to maxOpenConns and closes
        // each one after 10 idle minutes — so the value only takes part in the
        // pool key, and the default costs nothing.
        $this->connection = new ConnectionDto(
            dsn: $dsn,
            timeoutMs: $timeoutMs ?: 30000,
            maxOpenConns: $maxOpenConns ?: 0,
            maxIdleConns: $maxIdleConns ?: ($maxOpenConns ?: 0),
            connMaxLifetimeMs: $connMaxLifetimeMs ?: 0,
        );
    }

    /**
     * Streams a SELECT result row by row (batched). Each row is an associative
     * array keyed by column name.
     *
     * @param list<mixed> $bindings
     */
    public function query(string $sql, array $bindings = [], int $batchSize = 50): RowsResult
    {
        return new RowsResult(
            payload: new QueryPayload(
                method: $this->getMethod(),
                connection: $this->connection,
                sql: $sql,
                bindings: $bindings,
                batchSize: $batchSize,
            ),
        );
    }

    /**
     * Buffers the whole SELECT result into an array. Convenience over query() for
     * small result sets.
     *
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
     * Runs a non-row statement (INSERT/UPDATE/DELETE/DDL).
     *
     * @param list<mixed> $bindings
     */
    public function exec(string $sql, array $bindings = []): ExecResult
    {
        $taskResult = FeatureExecutor::exec(
            payload: new ExecPayload(
                method: $this->getMethod(),
                connection: $this->connection,
                sql: $sql,
                bindings: $bindings,
            ),
        );

        return ExecResult::fromPayload($taskResult->payload);
    }

    /**
     * Opens a transaction pinned to a single connection. Subsequent query/exec on
     * the returned Transaction run on it until commit() or rollback().
     */
    public function begin(int $isolationLevel = 0, bool $readOnly = false): Transaction
    {
        $taskResult = FeatureExecutor::exec(
            payload: new BeginPayload(
                method: $this->getMethod(),
                connection: $this->connection,
                isolationLevel: $isolationLevel,
                readOnly: $readOnly,
            ),
        );

        return new Transaction(
            method: $this->getMethod(),
            connection: $this->connection,
            transactionId: $taskResult->key,
        );
    }
}
