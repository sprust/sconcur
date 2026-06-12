<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Distinct;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncDistinctTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->seedDocuments();
    }

    protected function getCollectionName(): string
    {
        return 'distinct';
    }

    protected function on_1_start(): void
    {
        $result = $this->sconcurCollection->distinct(
            fieldName: $this->fieldName,
        );

        self::assertCount(5, $result);
    }

    protected function on_1_middle(): void
    {
        $result = $this->sconcurCollection->distinct(
            fieldName: $this->fieldName,
            filter: [$this->fieldName => ['$gt' => 3]],
        );

        self::assertCount(2, $result);
    }

    protected function on_2_start(): void
    {
        $result = $this->sconcurCollection->distinct(
            fieldName: $this->fieldName,
            filter: [$this->fieldName => ['$lte' => 2]],
        );

        self::assertCount(2, $result);
    }

    protected function on_2_middle(): void
    {
        $result = $this->sconcurCollection->distinct(
            fieldName: $this->fieldName,
        );

        self::assertCount(5, $result);
    }

    protected function on_iterate(): void
    {
        $result = $this->sconcurCollection->distinct(
            fieldName: $this->fieldName,
        );

        self::assertCount(5, $result);
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->distinct(
            fieldName: '$invalid',
            filter: ['$set' => 11],
        );
    }

    protected function assertResult(array $results): void
    {
        // no action
    }

    protected function seedDocuments(): void
    {
        $this->sconcurCollection->insertMany(
            documents: array_map(
                fn(int $index) => [$this->fieldName => $index],
                range(1, 5)
            )
        );
    }
}
