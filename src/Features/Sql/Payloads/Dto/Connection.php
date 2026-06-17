<?php

declare(strict_types=1);

namespace SConcur\Features\Sql\Payloads\Dto;

/**
 * Connection settings every SQL payload carries in its envelope: the driver DSN,
 * the per-statement timeout (the mandatory execution-time limit) and the pool
 * sizing. Mirrors Mongodb\Payloads\Dto\Connection.
 */
readonly class Connection
{
    public function __construct(
        public string $dsn,
        public int $timeoutMs,
        public int $maxOpenConns,
        public int $maxIdleConns,
        public int $connMaxLifetimeMs,
    ) {
    }
}
