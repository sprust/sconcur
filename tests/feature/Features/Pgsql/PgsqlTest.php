<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Pgsql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\TestPgsqlResolver;
use Throwable;

class PgsqlTest extends BaseAsyncTestCase
{
    protected Connection $connection;

    protected float $startTime = 0;
    protected float $endTime   = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestPgsqlResolver::getConnection();
    }

    protected function on_1_start(): void
    {
        $this->startTime = microtime(true);

        $this->sleepInDatabase();
    }

    protected function on_1_middle(): void
    {
        $this->sleepInDatabase();
    }

    protected function on_2_start(): void
    {
        $this->sleepInDatabase();
    }

    protected function on_2_middle(): void
    {
        $this->sleepInDatabase();
    }

    protected function on_iterate(): void
    {
        $this->endTime = microtime(true);
    }

    protected function on_exception(): void
    {
        $this->connection->fetchAll(
            sql: 'SELECT * FROM table_that_does_not_exist_42',
        );
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(
            str_contains($exception->getMessage(), 'pgsql:'),
            "Unexpected exception message: {$exception->getMessage()}",
        );
    }

    protected function assertResult(array $results): void
    {
        // Each task sleeps 50ms in the database twice (sequential within a task),
        // so a single task needs >= 100ms. Run concurrently the two tasks finish in
        // ~100ms; run sequentially they would take ~200ms.
        $totalTimeMs = ($this->endTime - $this->startTime) * 1000;

        self::assertTrue(
            $totalTimeMs >= 100,
            "Total time is less than 100ms but $totalTimeMs",
        );

        self::assertTrue(
            $totalTimeMs < 180,
            "Total time is not less than 180ms but $totalTimeMs",
        );
    }

    protected function sleepInDatabase(): void
    {
        $this->connection->fetchAll(
            sql: 'SELECT pg_sleep($1)',
            bindings: [0.05],
        );
    }
}
