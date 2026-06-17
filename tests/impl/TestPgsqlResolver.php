<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use SConcur\Features\Pgsql\Connection;

class TestPgsqlResolver
{
    protected static ?string $dsn = null;

    public static function getConnection(?int $timeoutMs = null, ?int $maxOpenConns = null): Connection
    {
        return new Connection(
            dsn: static::getDsn(),
            timeoutMs: $timeoutMs,
            maxOpenConns: $maxOpenConns,
        );
    }

    protected static function getDsn(): string
    {
        if (static::$dsn !== null) {
            return static::$dsn;
        }

        $host     = $_ENV['POSTGRES_HOST'];
        $port     = $_ENV['POSTGRES_PORT'];
        $database = $_ENV['POSTGRES_DB'];
        $user     = $_ENV['POSTGRES_USER'];
        $password = $_ENV['POSTGRES_PASSWORD'];

        return static::$dsn = "postgres://$user:$password@$host:$port/$database?sslmode=disable";
    }
}
