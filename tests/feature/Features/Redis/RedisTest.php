<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Features\Redis\Connection;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\TestRedisResolver;
use Throwable;

/**
 * The feature's async test: two coroutines issuing Redis commands at the same time, with
 * the event ordering and the concurrency the pattern checks for.
 *
 * The commands are BLPOP with a wait, because that is where concurrency is visible without
 * a stopwatch on microseconds: two waits of 500 ms each finish in about one of them.
 */
class RedisTest extends BaseAsyncTestCase
{
    protected Connection $connection;

    protected float $startTime = 0;

    protected float $endTime = 0;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    protected function on_1_start(): void
    {
        $this->startTime = microtime(true);

        $this->connection->blPop(['sconcur:test:queue:1'], timeoutSeconds: 0.5);
    }

    protected function on_1_middle(): void
    {
        $this->connection->set('sconcur:test:one', 'value-1');
    }

    protected function on_2_start(): void
    {
        $this->connection->blPop(['sconcur:test:queue:2'], timeoutSeconds: 0.5);
    }

    protected function on_2_middle(): void
    {
        $this->connection->set('sconcur:test:two', 'value-2');
    }

    protected function on_iterate(): void
    {
        $this->endTime = microtime(true);
    }

    protected function on_exception(): void
    {
        // WRONGTYPE: a list command against a string key.
        $this->connection->set('sconcur:test:string', 'not-a-list');
        $this->connection->command('LPUSH', ['sconcur:test:string', 'x']);
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(
            str_contains($exception->getMessage(), 'WRONGTYPE'),
            'Expected a WRONGTYPE failure, got: ' . $exception->getMessage(),
        );
    }

    protected function assertResult(array $results): void
    {
        self::assertSame('value-1', $this->connection->get('sconcur:test:one'));
        self::assertSame('value-2', $this->connection->get('sconcur:test:two'));

        // Both coroutines wait 500 ms. Run at the same time that is about half a second;
        // run one after the other it would be a full second.
        $totalTimeMs = ($this->endTime - $this->startTime) * 1000;

        self::assertTrue(
            $totalTimeMs >= 500,
            "Total time is less than 500ms but $totalTimeMs",
        );

        self::assertTrue(
            $totalTimeMs < 900,
            "Total time is not less than 900ms but $totalTimeMs — the waits did not overlap",
        );
    }
}
