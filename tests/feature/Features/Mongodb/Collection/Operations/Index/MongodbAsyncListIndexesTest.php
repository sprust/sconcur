<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Index;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncListIndexesTest extends BaseMongodbAsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->sconcurCollection->insertOne(['field_x' => 1]);
        $this->sconcurCollection->createIndex(['field_x' => 1]);
    }

    protected function getCollectionName(): string
    {
        return 'listIndexes';
    }

    protected function on_1_start(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();

        self::assertGreaterThanOrEqual(2, count($indexes));

        $hasIdIndex = false;

        foreach ($indexes as $index) {
            if (($index['name'] ?? '') === '_id_') {
                $hasIdIndex = true;
            }
        }

        self::assertTrue($hasIdIndex);
    }

    protected function on_1_middle(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();
        self::assertGreaterThanOrEqual(2, count($indexes));
    }

    protected function on_2_start(): void
    {
        $this->sconcurCollection->createIndex(['field_y' => 1]);

        $indexes = $this->sconcurCollection->listIndexes();
        self::assertGreaterThanOrEqual(3, count($indexes));
    }

    protected function on_2_middle(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();
        self::assertGreaterThanOrEqual(3, count($indexes));
    }

    protected function on_iterate(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();
        self::assertGreaterThanOrEqual(1, count($indexes));
    }

    protected function on_exception(): void
    {
        // listIndexes doesn't have filter-based errors easily
        // Use another operation to trigger exception
        $this->sconcurCollection->findOne(['$set' => 11]);
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
