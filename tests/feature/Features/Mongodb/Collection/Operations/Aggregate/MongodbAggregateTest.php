<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Aggregate;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\WaitGroup;

class MongodbAggregateTest extends BaseTestCase
{
    protected Collection $sconcurCollection;
    protected int $documentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $collectionName = 'op_Aggregate';

        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $this->seedDocuments();
    }

    public function testNoIteration(): void
    {
        $waitGroup = WaitGroup::create();

        $results = [];

        foreach (range(1, $this->documentsCount) as $ignored) {
            $waitGroup->add(
                callback: function () use (&$results) {
                    $iterator = $this->sconcurCollection->aggregate(
                        pipeline: []
                    );

                    foreach ($iterator as $item) {
                        $results[] = $item;

                        break;
                    }
                });

            $waitGroup->add(
                callback: function () {
                    $this->sconcurCollection->aggregate(
                        pipeline: []
                    );
                });
        }

        $waitGroup->waitAll();

        $this->assertCount($this->documentsCount, $results);
    }

    public function testBreakAtMulti(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () {
                $iterator = $this->sconcurCollection->aggregate(
                    pipeline: [],
                    batchSize: 1
                );

                foreach ($iterator as $ignored) {
                    break;
                }

                $document = $this->sconcurCollection->findOne([]);

                self::assertTrue(
                    is_array($document)
                );

                self::assertArrayHasKey(
                    '_id',
                    $document
                );

                self::assertCount(
                    2,
                    $document
                );
            }
        );

        $waitGroup->waitAll();
    }

    public function testRewind(): void
    {
        $waitGroup = WaitGroup::create();

        $counter = 0;

        $waitGroup->add(
            callback: function () use (&$counter) {
                $iterator = $this->sconcurCollection->aggregate(
                    pipeline: [],
                    batchSize: 1
                );

                foreach ($iterator as $ignored) {
                    ++$counter;

                    break;
                }

                foreach ($iterator as $ignored) {
                    ++$counter;
                }
            }
        );

        $waitGroup->waitAll();

        self::assertEquals(
            $this->documentsCount + 1,
            $counter
        );
    }

    private function seedDocuments(): void
    {
        $this->sconcurCollection->deleteMany(
            filter: []
        );

        $this->documentsCount = 10;

        $this->sconcurCollection->insertMany(
            documents: array_map(
                static fn(int $index) => [
                    uniqid() => $index,
                ],
                range(1, $this->documentsCount)
            )
        );
    }
}
