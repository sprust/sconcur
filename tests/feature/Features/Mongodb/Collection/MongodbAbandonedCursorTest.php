<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\WaitGroup;

/**
 * An iterator abandoned before exhaustion (early break) used to leave the
 * server-side cursor open until the MongoDB cursor timeout. It must be
 * killed as soon as the owning flow is released.
 */
class MongodbAbandonedCursorTest extends BaseTestCase
{
    protected string $collectionName = 'abandonedCursor';

    protected function setUp(): void
    {
        parent::setUp();

        $driverCollection = TestMongodbResolver::getDriverTestCollection($this->collectionName);

        $driverCollection->deleteMany([]);

        $driverCollection->insertMany(
            array_map(
                static fn(int $index) => ['index' => $index],
                range(1, 60)
            )
        );
    }

    public function testSyncEarlyBreak(): void
    {
        $collection = TestMongodbResolver::getSconcurTestCollection($this->collectionName);

        $openCursorsBaseline = $this->getOpenCursorsCount();

        $iterator = $collection->find(
            filter: [],
            batchSize: 5,
        );

        $firstDocument = null;

        foreach ($iterator as $document) {
            $firstDocument = $document;

            break;
        }

        self::assertNotNull($firstDocument);

        unset($iterator);

        $this->assertOpenCursorsReturnTo($openCursorsBaseline);
    }

    public function testAsyncEarlyBreak(): void
    {
        $collection = TestMongodbResolver::getSconcurTestCollection($this->collectionName);

        $openCursorsBaseline = $this->getOpenCursorsCount();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function () use ($collection): int {
                $documentsSeen = 0;

                foreach (
                    $collection->find(
                        filter: [],
                        batchSize: 5,
                    ) as $document
                ) {
                    ++$documentsSeen;

                    break;
                }

                return $documentsSeen;
            },
        );

        $results = $waitGroup->waitResults();

        self::assertEquals([1], array_values($results));

        $this->assertOpenCursorsReturnTo($openCursorsBaseline);
    }

    protected function assertOpenCursorsReturnTo(int $baseline): void
    {
        $deadline = microtime(true) + 5.0;

        $openCursors = $this->getOpenCursorsCount();

        while (microtime(true) < $deadline) {
            $openCursors = $this->getOpenCursorsCount();

            if ($openCursors <= $baseline) {
                break;
            }

            usleep(10_000);
        }

        self::assertLessThanOrEqual(
            $baseline,
            $openCursors,
            'Abandoned server-side cursor was not closed'
        );
    }

    protected function getOpenCursorsCount(): int
    {
        $serverStatus = TestMongodbResolver::getDriverTestDatabase()
            ->command(['serverStatus' => 1])
            ->toArray()[0];

        return (int) $serverStatus->metrics->cursor->open->total;
    }
}
