<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql;

use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Connection as SqlConnection;

/**
 * MySQL connection facade over the shared SQL feature. The DSN is the
 * go-sql-driver/mysql format, e.g.:
 *
 *     user:pass@tcp(127.0.0.1:3306)/dbname?parseTime=true
 *
 * Use `?` placeholders with a positional bindings list.
 *
 * Client-side parameter interpolation (`interpolateParams=true`) is enabled by
 * default: each query is one round-trip (a single COM_QUERY) instead of the
 * driver's PREPARE + EXECUTE + CLOSE, matching PDO's default behaviour. Pass
 * `interpolateParams=false` in the DSN to use server-side prepared statements.
 */
readonly class Connection extends SqlConnection
{
    public function __construct(
        string $dsn,
        ?int $timeoutMs = null,
        ?int $maxOpenConns = null,
        ?int $maxIdleConns = null,
        ?int $connMaxLifetimeMs = null,
    ) {
        parent::__construct(
            dsn: static::withInterpolateParams($dsn),
            timeoutMs: $timeoutMs,
            maxOpenConns: $maxOpenConns,
            maxIdleConns: $maxIdleConns,
            connMaxLifetimeMs: $connMaxLifetimeMs,
        );
    }

    protected function getMethod(): MethodEnum
    {
        return MethodEnum::Mysql;
    }

    /**
     * Adds `interpolateParams=true` to the DSN unless it already sets the flag, so
     * the perf default applies without overriding an explicit choice.
     */
    protected static function withInterpolateParams(string $dsn): string
    {
        if (str_contains($dsn, 'interpolateParams=')) {
            return $dsn;
        }

        $separator = str_contains($dsn, '?') ? '&' : '?';

        return $dsn . $separator . 'interpolateParams=true';
    }
}
