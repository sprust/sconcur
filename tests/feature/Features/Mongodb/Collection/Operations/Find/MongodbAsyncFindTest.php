<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Find;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncFindTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;
    protected int $documentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName      = uniqid();
        $this->documentsCount = 25;

        $this->seedDocuments();
    }

    protected function getCollectionName(): string
    {
        return 'find';
    }

    protected function on_1_start(): void
    {
        $results = iterator_to_array(
            $this->sconcurCollection->find(
                filter: [$this->fieldName => ['$exists' => true]],
                batchSize: 5,
            )
        );

        self::assertCount($this->documentsCount, $results);

        foreach ($results as $result) {
            self::assertArrayHasKey($this->fieldName, $result);
        }
    }

    protected function on_1_middle(): void
    {
        $results = iterator_to_array(
            $this->sconcurCollection->find(
                filter: [$this->fieldName => ['$exists' => true]],
                limit: 3,
            )
        );

        self::assertCount(3, $results);
    }

    protected function on_2_start(): void
    {
        $results = iterator_to_array(
            $this->sconcurCollection->find(
                filter: [$this->fieldName => ['$exists' => true]],
                sort: [$this->fieldName => 1],
                limit: 10,
            )
        );

        self::assertCount(10, $results);

        // Verify sorting
        $previous = 0;

        foreach ($results as $result) {
            self::assertGreaterThan($previous, $result[$this->fieldName]);
            $previous = $result[$this->fieldName];
        }
    }

    protected function on_2_middle(): void
    {
        $results = iterator_to_array(
            $this->sconcurCollection->find(
                filter: [$this->fieldName => ['$exists' => true]],
                sort: [$this->fieldName => -1],
                limit: 5,
            )
        );

        self::assertCount(5, $results);
    }

    protected function on_iterate(): void
    {
        $results = iterator_to_array(
            $this->sconcurCollection->find(
                filter: [$this->fieldName => ['$exists' => true]],
                limit: 1,
            )
        );

        self::assertCount(1, $results);
    }

    protected function on_exception(): void
    {
        iterator_to_array(
            $this->sconcurCollection->find(
                filter: ['$set' => 11],
            )
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
                range(1, $this->documentsCount)
            )
        );
    }
}
