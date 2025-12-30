<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Aggregate;

use SConcur\Entities\Context;
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

        $this->seedDocuments(Context::create(1));
    }

    public function testNoIteration(): void
    {
        $context = Context::create(2);

        $waitGroup = WaitGroup::create($context);

        $results = [];

        foreach (range(1, $this->documentsCount) as $ignored) {
            $waitGroup->add(
                callback: function (Context $context) use (&$results) {
                    $iterator = $this->sconcurCollection->aggregate(
                        context: $context,
                        pipeline: []
                    );

                    foreach ($iterator as $item) {
                        $results[] = $item;

                        break;
                    }
                });

            $waitGroup->add(
                callback: function (Context $context) {
                    $this->sconcurCollection->aggregate(
                        context: $context,
                        pipeline: []
                    );
                });
        }

        $waitGroup->waitAll();

        $this->assertCount($this->documentsCount, $results);
    }

    // TODO
    // public function testBreakAtMulti(): void
    // {
    //     $context = Context::create(2);
    //
    //     $waitGroup = WaitGroup::create($context);
    //
    //     $waitGroup->add(
    //         callback: function (Context $context) {
    //             $iterator = $this->sconcurCollection->aggregate(
    //                 context: $context,
    //                 pipeline: [],
    //                 batchSize: 1
    //             );
    //
    //             foreach ($iterator as $ignored) {
    //                 // no action
    //
    //                 break;
    //             }
    //
    //             $document = $this->sconcurCollection->findOne($context, []);
    //
    //             self::assertTrue(
    //                 is_array($document)
    //             );
    //
    //             self::assertCount(
    //                 1,
    //                 $document
    //             );
    //
    //             $keys = array_keys($document);
    //
    //             self::assertIsInt(
    //                 $document[$keys[0]]
    //             );
    //         }
    //     );
    //
    //     $waitGroup->waitAll();
    // }
    //
    // public function testRewind(): void
    // {
    //     $context = Context::create(2);
    //
    //     $waitGroup = WaitGroup::create($context);
    //
    //     $counter = 0;
    //
    //     $waitGroup->add(
    //         callback: function (Context $context) use (&$counter) {
    //             $iterator = $this->sconcurCollection->aggregate(
    //                 context: $context,
    //                 pipeline: [],
    //                 batchSize: 1
    //             );
    //
    //             foreach ($iterator as $ignored) {
    //                 ++$counter;
    //
    //                 break;
    //             }
    //
    //             foreach ($iterator as $ignored) {
    //                 ++$counter;
    //             }
    //         }
    //     );
    //
    //     $waitGroup->waitAll();
    //
    //     self::assertEquals(
    //         $this->documentsCount + 1,
    //         $counter
    //     );
    // }

    private function seedDocuments(Context $context): void
    {
        $this->sconcurCollection->deleteMany(
            context: $context,
            filter: []
        );

        $this->documentsCount = 10;

        $this->sconcurCollection->insertMany(
            context: $context,
            documents: array_map(
                static fn(int $index) => [
                    uniqid() => $index,
                ],
                range(1, $this->documentsCount)
            )
        );
    }
}
