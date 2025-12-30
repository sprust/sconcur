<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Aggregate;

use SConcur\Entities\Context;
use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncAggregateTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected int $pagesCount;
    protected int $pageSize;
    protected int $documentsCount;

    /**
     * @var array<string, array<int, bool>>
     */
    protected array $results;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->pagesCount     = 4;
        $this->pageSize       = 30;
        $this->documentsCount = $this->pagesCount * $this->pageSize;

        $this->seedData();

        $this->results = [];
    }

    protected function getCollectionName(): string
    {
        return 'aggregate';
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

    protected function on_exception(Context $context): void
    {
        $iterator = $this->sconcurCollection->aggregate(
            context: $context,
            /** @phpstan-ignore-next-line argument.type */
            pipeline: [$this->fieldName => $this->sconcurObjectId],
        );

        $iterator->rewind();
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
                $documents,
                "Failed asserting documents count in order [$order]"
            );

            $index = 0;

            $documentIndexes = array_keys($documents);

            foreach ($documentIndexes as $documentIndex) {
                $index++;

                self::assertEquals(
                    $index,
                    $documentIndex,
                    sprintf(
                        "Document index [%d] is not equal to expected index [%d] in order [%s]",
                        $documentIndex,
                        $index,
                        $order
                    )
                );
            }

            self::assertEquals(
                $this->documentsCount,
                $index
            );
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
        $this->results[$order] = [];

        $batchSize = $this->pageSize - 2;

        foreach (range(1, $this->pagesCount + 1) as $page) {
            $aggregation = $this->sconcurCollection->aggregate(
                context: $context,
                pipeline: [
                    [
                        '$match' => [
                            $this->fieldName => $this->sconcurObjectId,
                        ],
                    ],
                    [
                        '$sort' => [
                            'index' => 1,
                        ],
                    ],
                    [
                        '$skip' => ($page - 1) * $this->pageSize,
                    ],
                    [
                        '$limit' => $this->pageSize,
                    ],
                ],
                batchSize: $batchSize,
            );

            foreach ($aggregation as $item) {
                $this->results[$order][$item['index']] = true;
            }

            ++$batchSize;
        }
    }
}
