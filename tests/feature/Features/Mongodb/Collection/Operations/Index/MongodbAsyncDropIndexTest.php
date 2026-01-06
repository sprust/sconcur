<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Index;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncDropIndexTest extends BaseMongodbAsyncTestCase
{
    protected int $indexesCount;

    protected function setUp(): void
    {
        parent::setUp();

        if (iterator_count($this->driverCollection->listIndexes()) > 0) {
            $this->driverCollection->dropIndexes();
        }

        $this->indexesCount = 0;
    }

    protected function getCollectionName(): string
    {
        return 'dropIndex';
    }

    protected function on_1_start(): void
    {
        $this->createIndex(
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: __FUNCTION__
        );

        $this->assertIndex(__FUNCTION__);

        $this->dropIndex(index: __FUNCTION__);

        $this->assertIndexDoesntExist(__FUNCTION__);
    }

    protected function on_1_middle(): void
    {
        $keys = [
            __FUNCTION__ => 1,
            uniqid()     => -1,
        ];

        $this->createIndex(
            keys: $keys,
            name: null
        );

        $indexName = $this->sconcurCollection->makeIndexNameByKeys($keys);

        $this->assertIndex($indexName);

        $this->dropIndex(index: $keys);

        $this->assertIndexDoesntExist($indexName);
    }

    protected function on_2_start(): void
    {
        $this->createIndex(
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: __FUNCTION__
        );

        $this->createIndex(
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: uniqid()
        );

        $this->assertIndex(__FUNCTION__);

        $this->dropIndex(index: __FUNCTION__);

        $this->assertIndexDoesntExist(__FUNCTION__);
    }

    protected function on_2_middle(): void
    {
        $keys = [
            __FUNCTION__ => 1,
            uniqid()     => -1,
        ];

        $this->createIndex(
            keys: $keys,
            name: null
        );

        $this->createIndex(
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: uniqid()
        );

        $indexName = $this->sconcurCollection->makeIndexNameByKeys($keys);

        $this->assertIndex($indexName);

        $this->dropIndex(index: $keys);

        $this->assertIndexDoesntExist($indexName);
    }

    protected function on_iterate(): void
    {
        $this->createIndex(
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: null
        );
    }

    protected function on_exception(): void
    {
        $this->createIndex(
            keys: [],
            name: null
        );
    }

    protected function assertResult(array $results): void
    {
        self::assertCount(
            $this->indexesCount + 1,
            $this->driverCollection->listIndexes()
        );
    }

    /**
     * @param array<string, int|string> $keys
     */
    protected function createIndex(array $keys, ?string $name): void
    {
        $this->sconcurCollection->createIndex(
            keys: $keys,
            name: $name
        );

        ++$this->indexesCount;
    }

    /**
     * @param array<string, int|string> $index
     */
    protected function dropIndex(array|string $index): void
    {
        $this->sconcurCollection->dropIndex(
            index: $index
        );

        --$this->indexesCount;
    }

    protected function assertIndex(string $name): void
    {
        $indexes = [];

        foreach ($this->driverCollection->listIndexes() as $index) {
            $indexes[] = $index->getName();
        }

        self::assertTrue(
            in_array($name, $indexes)
        );
    }

    protected function assertIndexDoesntExist(string $name): void
    {
        $indexes = [];

        foreach ($this->driverCollection->listIndexes() as $index) {
            $indexes[] = $index->getName();
        }

        self::assertFalse(
            in_array($name, $indexes)
        );
    }
}
