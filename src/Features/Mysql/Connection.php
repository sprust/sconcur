<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql;

use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Connection as SqlConnection;

/**
 * MySQL connection facade over the shared SQL feature. The DSN is the
 * go-sql-driver/mysql format, e.g.:
 *
 *     user:pass@tcp(127.0.0.1:3306)/dbname
 *
 * Use `?` placeholders with a positional bindings list.
 *
 * The core picks the protocol per statement: one with bindings goes as a
 * prepared statement, one without as text. Which DSN parameters are applied, and
 * what the driver settles on its own, are in docs/mysql.md.
 */
readonly class Connection extends SqlConnection
{
    protected function getMethod(): MethodEnum
    {
        return MethodEnum::Mysql;
    }
}
