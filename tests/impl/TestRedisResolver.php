<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use Redis as NativeRedis;
use SConcur\Features\Redis\Connection;

class TestRedisResolver
{
    /**
     * The database the tests work in. Kept apart from 0 so a flush here can never take
     * anything that was not put there by a test.
     */
    public static int $database = 9;

    public static function getConnection(
        ?int $timeoutMs = null,
        ?int $poolSize = null,
    ): Connection {
        return new Connection(
            dsn: static::getDsn(),
            timeoutMs: $timeoutMs,
            poolSize: $poolSize,
        );
    }

    public static function getDsn(?int $database = null): string
    {
        $host     = $_ENV['REDIS_HOST'];
        $port     = $_ENV['REDIS_PORT'];
        $password = rawurlencode((string) $_ENV['REDIS_PASSWORD']);

        return "redis://:$password@$host:$port/" . ($database ?? static::$database);
    }

    /**
     * Native phpredis connection — the baseline the SConcur paths are compared against in
     * benchmarks, and the reference the parity tests check replies against.
     */
    public static function getNativeRedis(): NativeRedis
    {
        $redis = new NativeRedis();

        $redis->connect((string) $_ENV['REDIS_HOST'], (int) $_ENV['REDIS_PORT']);
        $redis->auth((string) $_ENV['REDIS_PASSWORD']);
        $redis->select(static::$database);

        return $redis;
    }

    /**
     * Empties the test database.
     *
     * Through SConcur rather than through phpredis on purpose: the suite must run against
     * the feature alone, and only the parity test and the benchmarks have a reason to need
     * the native extension present.
     */
    public static function flush(): void
    {
        static::getConnection()->flushDb(confirm: true);
    }

    /** How many clients the server has, for the tests that check nothing is left behind. */
    public static function countServerConnections(): int
    {
        $clients = (string) static::getConnection()->command('CLIENT', ['LIST']);

        return substr_count($clients, "\n");
    }

    /**
     * How many connections the server has accepted since it started.
     *
     * Different from the count above in the one way that matters for reuse: a
     * client that opens and closes a connection per command leaves the open count
     * flat while this one climbs.
     */
    public static function countAcceptedConnections(): int
    {
        $info = (string) static::getConnection()->command('INFO', ['stats']);

        if (preg_match('/^total_connections_received:(\d+)/m', $info, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * A connection to a port nothing listens on, for the tests about what happens
     * when a dial does not finish.
     */
    public static function getUnreachableConnection(?int $timeoutMs = null): Connection
    {
        // A blackholed address rather than a closed port: a closed port is refused
        // at once, and what these tests need is a dial that hangs until something
        // stops it.
        return new Connection(
            dsn: 'redis://10.255.255.1:6379/0',
            timeoutMs: $timeoutMs,
        );
    }
}
