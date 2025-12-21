<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb;

use MongoDB\Client;
use MongoDB\Collection;
use SConcur\Entities\Context;
use SConcur\Features\Features;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;

/**
 * @template T
 */
abstract class BaseMongodbRangeTestCase extends BaseTestCase
{
    protected MongodbFeature $feature;
    protected Collection $driverCollection;

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

        $connectionParameters = new ConnectionParameters(
            uri: TestMongodbUriResolver::get(),
            database: 'u-test',
            collection: 'type' . ucfirst($this->getType()),
        );

        $this->driverCollection = new Client($connectionParameters->uri)
            ->selectDatabase($connectionParameters->database)
            ->selectCollection($connectionParameters->collection);

        $this->feature = Features::mongodb($connectionParameters);

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

        $this->feature->insertMany(
            context: $context,
            documents: $documents
        );

        $aggregation = $this->feature->aggregate(
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

        $aggregation = $this->feature->aggregate(
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
