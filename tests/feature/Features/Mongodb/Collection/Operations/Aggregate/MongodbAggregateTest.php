<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Aggregate;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;
use SConcur\WaitGroup;

class MongodbAggregateTest extends BaseTestCase
{
    private Collection $sconcurCollection;

    protected function setUp(): void
    {
        parent::setUp();

        $uri        = TestMongodbUriResolver::get();
        $database   = 'u-test';
        $collection = 'op_Aggregate';

        $this->sconcurCollection = new Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);
    }

    public function testNoIteration(): void
    {
        $context = Context::create(2);

        $this->sconcurCollection->deleteMany(
            context: $context,
            filter: []
        );

        $documentsCount = 10;

        $this->sconcurCollection->insertMany(
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

        $this->assertCount($documentsCount, $results);
    }
}
