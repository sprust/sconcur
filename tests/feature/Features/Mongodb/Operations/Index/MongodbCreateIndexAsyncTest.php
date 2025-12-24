<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Operations\Index;

use SConcur\Entities\Context;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbAsyncTestCase;

class MongodbCreateIndexAsyncTest extends BaseMongodbAsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (iterator_count($this->driverCollection->listIndexes()) > 0) {
            $this->driverCollection->dropIndexes();
        }
    }

    protected function getCollectionName(): string
    {
        return 'createIndex';
    }

    protected function on_1_start(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ]
        );
    }

    protected function on_1_middle(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ]
        );
    }

    protected function on_2_start(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ]
        );
    }

    protected function on_2_middle(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ]
        );
    }

    protected function on_iterate(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ]
        );
    }

    protected function on_exception(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: []
        );
    }

    protected function assertResult(array $results): void
    {
        self::assertEquals(
            6 + 1, // +1 -> _id
            iterator_count($this->driverCollection->listIndexes())
        );
    }

    /**
     * @param array<string, int|string> $keys
     */
    protected function createIndex(Context $context, array $keys): void
    {
        $this->feature->createIndex(
            context: $context,
            keys: $keys
        );
    }
}
