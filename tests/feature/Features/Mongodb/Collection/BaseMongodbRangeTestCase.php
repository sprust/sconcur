<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

/**
 * @template T
 */
abstract class BaseMongodbRangeTestCase extends BaseTestCase
{
    protected \MongoDB\Collection $driverCollection;
    protected Collection $sconcurCollection;

    abstract protected function getType(): string;

    /**
     * @return T
     */
    abstract protected function firstValue(): mixed;

    /**
     * @param T $value
     *
     * @return T
     */
    abstract protected function nextValue(mixed $value): mixed;

    /**
     * @param T $value
     *
     * @return T
     */
    abstract protected function prevValue(mixed $value): mixed;

    protected function setUp(): void
    {
        parent::setUp();

        $collectionName = 'range_' . ucfirst($this->getType());

        $this->driverCollection  = TestMongodbResolver::getDriverTestCollection($collectionName);
        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $this->driverCollection->deleteMany([]);
    }

    public function testRange(): void
    {
        $valuesCount = 10;

        $documents = [];

        $currentValue = $this->firstValue();

        /** @var T[] $values */
        $values = [];

        $caseId = uniqid();

        foreach (range(1, $valuesCount) as $index) {
            $currentValue = $this->nextValue($currentValue);

            $values[] = $currentValue;

            $documents[] = [
                'index' => $index,
                'case'  => $caseId,
                'value' => $currentValue,
            ];
        }

        $firstValue = $values[0];
        $lastValue  = $values[count($values) - 1];

        $this->sconcurCollection->insertMany(
            documents: $documents
        );

        $aggregation = $this->sconcurCollection->aggregate(
            pipeline: [
                [
                    '$match' => [
                        'case'  => $caseId,
                        'value' => [
                            '$gte' => $this->nextValue($firstValue),
                            '$lte' => $this->prevValue($lastValue),
                        ],
                    ],
                ],
            ]
        );

        self::assertEquals(
            $valuesCount - 2,
            iterator_count($aggregation)
        );

        $aggregation = $this->sconcurCollection->aggregate(
            pipeline: [
                [
                    '$match' => [
                        'case'  => $caseId,
                        'value' => [
                            '$gte' => $this->prevValue($firstValue),
                            '$lte' => $this->nextValue($lastValue),
                        ],
                    ],
                ],
            ]
        );

        self::assertEquals(
            $valuesCount,
            iterator_count($aggregation)
        );
    }
}
