<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;

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

        $uri        = TestMongodbUriResolver::get();
        $database   = 'u-test';
        $collection = 'range_' . ucfirst($this->getType());

        $this->driverCollection = new \MongoDB\Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);

        $this->sconcurCollection = new Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);

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

        $context = Context::create(3);

        $this->sconcurCollection->insertMany(
            context: $context,
            documents: $documents
        );

        $aggregation = $this->sconcurCollection->aggregate(
            context: $context,
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
            context: $context,
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
