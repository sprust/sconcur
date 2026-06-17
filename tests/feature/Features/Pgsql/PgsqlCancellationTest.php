<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Pgsql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestPgsqlResolver;
use SConcur\WaitGroup;

class PgsqlCancellationTest extends BaseTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestPgsqlResolver::getConnection();
    }

    public function testStopCancelsInFlightQuery(): void
    {
        $connection = $this->connection;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function () use ($connection): string {
            $connection->fetchAll(sql: 'SELECT pg_sleep(5)');

            return 'should-not-complete';
        });

        // The pg_sleep(5) is in flight after add(). stop() cancels the flow, so
        // iterate() yields nothing and returns immediately instead of waiting 5s — and
        // the Go side cancels the running query (tearDown asserts no dangling tasks).
        $waitGroup->stop();

        $startedAt = microtime(true);

        $results = iterator_to_array($waitGroup->iterate());

        $elapsedSeconds = microtime(true) - $startedAt;

        self::assertCount(0, $results);
        self::assertLessThan(4, $elapsedSeconds);
    }
}
