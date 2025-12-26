<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Operations\Aggregate;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;
use SConcur\WaitGroup;

class MongodbAggregateTest extends BaseTestCase
{
    public function test(): void
    {
        $context = Context::create(2);

        $uri        = TestMongodbUriResolver::get();
        $database   = 'u-test';
        $collection = 'op_Aggregate';

        $driverCollection = new \MongoDB\Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);

        $sconcurCollection = new Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);

        $driverCollection->deleteMany([]);

        $sconcurCollection->bulkWrite(
            context: $context,
            operations: [
                [
                    'deleteMany' => [
                        [],
                    ],
                ],
            ]
        );

        $documentsCount = 10;

        $sconcurCollection->insertMany(
            context: $context,
            documents: array_map(
                static fn(int $index) => [
                    uniqid() => $index,
                ],
                range(1, $documentsCount)
            )
        );

        $waitGroup = WaitGroup::create($context);

        $results = [];

        foreach (range(1, $documentsCount) as $ignored) {
            $waitGroup->add(
                callback: function (Context $context) use ($sconcurCollection, &$results) {
                    $iterator = $sconcurCollection->aggregate(
                        context: $context,
                        pipeline: []
                    );

                    foreach ($iterator as $item) {
                        $results[] = $item;

                        break;
                    }
                });

            $waitGroup->add(
                callback: function (Context $context) use ($sconcurCollection) {
                    $sconcurCollection->aggregate(
                        context: $context,
                        pipeline: []
                    );
                });
        }

        $waitGroup->waitAll();

        $this->assertCount($documentsCount, $results);
    }
}
