<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Operations\Index;

use SConcur\Entities\Context;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbAsyncTestCase;

class MongodbAsyncCreateIndexTest extends BaseMongodbAsyncTestCase
{
    /** @var array<string> */
    protected array $createdIndexNames;

    protected function setUp(): void
    {
        parent::setUp();

        if (iterator_count($this->driverCollection->listIndexes()) > 0) {
            $this->driverCollection->dropIndexes();
        }

        $this->createdIndexNames = [];
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
            ],
            name: __FUNCTION__
        );

        $this->createdIndexNames[] = __FUNCTION__;
    }

    protected function on_1_middle(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: __FUNCTION__
        );

        $this->createdIndexNames[] = __FUNCTION__;
    }

    protected function on_2_start(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: __FUNCTION__
        );

        $this->createdIndexNames[] = __FUNCTION__;
    }

    protected function on_2_middle(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: __FUNCTION__
        );

        $this->createdIndexNames[] = __FUNCTION__;
    }

    protected function on_iterate(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [
                __FUNCTION__ => 1,
                uniqid()     => -1,
            ],
            name: null
        );
    }

    protected function on_exception(Context $context): void
    {
        $this->createIndex(
            context: $context,
            keys: [],
            name: null
        );
    }

    protected function assertResult(array $results): void
    {
        $iterator = $this->driverCollection->listIndexes();

        $existIndexNames = [];

        foreach ($iterator as $item) {
            $existIndexNames[$item->getName()] = true;
        }

        self::assertCount(
            6 + 1, // +1 -> _id
            $existIndexNames
        );

        foreach ($this->createdIndexNames as $createdIndexName) {
            self::assertArrayHasKey($createdIndexName, $existIndexNames);
        }
    }

    /**
     * @param array<string, int|string> $keys
     */
    protected function createIndex(Context $context, array $keys, ?string $name): void
    {
        $this->feature->createIndex(
            context: $context,
            keys: $keys,
            name: $name
        );
    }
}
