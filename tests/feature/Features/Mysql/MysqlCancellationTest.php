<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mysql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMysqlResolver;
use SConcur\WaitGroup;

class MysqlCancellationTest extends BaseTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestMysqlResolver::getConnection();
    }

    public function testStopCancelsInFlightQuery(): void
    {
        $connection = $this->connection;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function () use ($connection): string {
            $connection->fetchAll(sql: 'SELECT SLEEP(5)');

            return 'should-not-complete';
        });

        // The SELECT SLEEP(5) is in flight after add(). stop() cancels the flow, so
        // iterate() yields nothing and returns immediately instead of waiting 5s — and
        // the Go side kills the running query (tearDown asserts no dangling tasks).
        $waitGroup->stop();

        $startedAt = microtime(true);

        $results = iterator_to_array($waitGroup->iterate());

        $elapsedSeconds = microtime(true) - $startedAt;

        self::assertCount(0, $results);
        self::assertLessThan(4, $elapsedSeconds);
    }
}
