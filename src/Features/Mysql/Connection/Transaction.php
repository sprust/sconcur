<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql\Connection;

use SConcur\Features\Mysql\Results\ExecResult;
use SConcur\Features\Mysql\Results\QueryResult;

readonly class Transaction
{
    public function __construct(
        public Client $client,
        public string $txKey,
    ) {
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): QueryResult
    {
        return $this->client->queryInternal(
            sql: $sql,
            bindings: $bindings,
            txKey: $this->txKey,
        );
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function exec(string $sql, array $bindings = []): ExecResult
    {
        return $this->client->execInternal(
            sql: $sql,
            bindings: $bindings,
            txKey: $this->txKey,
        );
    }

    public function commit(): void
    {
        $this->client->commit($this->txKey);
    }

    public function rollback(): void
    {
        $this->client->rollback($this->txKey);
    }
}
