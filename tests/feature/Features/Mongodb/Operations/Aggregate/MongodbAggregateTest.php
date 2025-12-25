<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Operations\Aggregate;

use SConcur\Entities\Context;
use SConcur\Features\Features;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;
use SConcur\WaitGroup;

class MongodbAggregateTest extends BaseTestCase
{
    public function test(): void
    {
        $context = Context::create(2);

        $connectionParameters = new ConnectionParameters(
            uri: TestMongodbUriResolver::get(),
            database: 'u-test',
            collection: 'op_Aggregate',
        );

        $feature = Features::mongodb($connectionParameters);

        $feature->bulkWrite(
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

        $feature->insertMany(
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
                callback: function (Context $context) use ($feature, &$results) {
                    $iterator = $feature->aggregate(
                        context: $context,
                        pipeline: []
                    );

                    foreach ($iterator as $item) {
                        $results[] = $item;

                        break;
                    }
                });

            $waitGroup->add(
                callback: function (Context $context) use ($feature) {
                    $feature->aggregate(
                        context: $context,
                        pipeline: []
                    );
                });
        }

        $waitGroup->waitAll();

        $this->assertCount($documentsCount, $results);
    }
}
