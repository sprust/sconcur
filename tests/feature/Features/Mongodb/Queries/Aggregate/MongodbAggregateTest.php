<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Queries\Aggregate;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbTestCase;

class MongodbAggregateTest extends BaseMongodbTestCase
{
    protected \MongoDB\BSON\ObjectId $driverObjectId;

    protected string $fieldName;
    protected ObjectId $fieldValue;

    protected int $documentsCount;

    /**
     * @var array<string, array<int, bool>>
     */
    protected array $results;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverObjectId = new \MongoDB\BSON\ObjectId('693a7119e9d4885085366c80');

        $this->fieldName  = uniqid();
        $this->fieldValue = new ObjectId('693a7119e9d4885085366c80');

        $this->documentsCount = 1000;

        $this->seedData();

        $this->results = [];
    }

    protected function on_1_start(Context $context): void
    {
        $this->aggregate(
            context: $context,
            order: '_1',
        );
    }

    protected function on_1_middle(Context $context): void
    {
        $this->aggregate(
            context: $context,
            order: '_1.1',
        );
    }

    protected function on_2_start(Context $context): void
    {
        $this->aggregate(
            context: $context,
            order: '_2',
        );
    }

    protected function on_2_middle(Context $context): void
    {
        $this->aggregate(
            context: $context,
            order: '_2.1',
        );
    }

    protected function on_iterate(Context $context): void
    {
        $this->aggregate(
            context: $context,
            order: '_sync',
        );
    }

    protected function assertResult(array $results): void
    {
        self::assertCount(
            5,
            $this->results
        );

        foreach ($this->results as $order => $documents) {
            self::assertCount(
                $this->documentsCount,
                $documents
            );

            $index = 0;

            $documentIndexes = array_keys($documents);

            foreach ($documentIndexes as $documentIndex) {
                $index++;

                self::assertEquals(
                    $index,
                    $documentIndex,
                    "Document index is not equal to expected index in order [$order]"
                );
            }
        }
    }

    protected function seedData(): void
    {
        $this->driverCollection->insertMany(
            array_map(
                fn(int $index) => [
                    'index'          => $index,
                    $this->fieldName => $this->driverObjectId,
                ],
                range(1, $this->documentsCount)
            ),
        );
    }

    protected function aggregate(Context $context, string $order): void
    {
        $aggregation = $this->feature->aggregate(
            context: $context,
            pipeline: [
                [
                    '$match' => [
                        $this->fieldName => $this->fieldValue,
                    ],
                ],
                ['$sort' => ['index' => 1]],
            ],
        );

        $this->results[$order] = [];

        foreach ($aggregation as $item) {
            $this->results[$order][$item['index']] = true;
        }
    }
}
