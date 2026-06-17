<?php

declare(strict_types=1);

namespace SConcur\Features\Pgsql;

use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Connection as SqlConnection;

/**
 * PostgreSQL connection facade over the shared SQL feature (driver: jackc/pgx via
 * database/sql). The DSN is the pgx/libpq format, e.g.:
 *
 *     postgres://user:pass@127.0.0.1:5432/dbname?sslmode=disable
 *
 * Use `$1, $2, …` placeholders with a positional bindings list. Unlike MySQL there
 * is no last-insert-id: use `INSERT … RETURNING id` and read it as a row.
 */
readonly class Connection extends SqlConnection
{
    protected function getMethod(): MethodEnum
    {
        return MethodEnum::Pgsql;
    }
}
