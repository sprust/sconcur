<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Index;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncCreateIndexesTest extends BaseMongodbAsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->sconcurCollection->insertOne(['field_a' => 1, 'field_b' => 2]);
    }

    protected function getCollectionName(): string
    {
        return 'createIndexes';
    }

    protected function on_1_start(): void
    {
        $names = $this->sconcurCollection->createIndexes([
            ['keys' => ['field_a' => 1]],
            ['keys' => ['field_b' => -1]],
        ]);

        self::assertCount(2, $names);
    }

    protected function on_1_middle(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();
        // _id + field_a + field_b = 3
        self::assertGreaterThanOrEqual(3, count($indexes));
    }

    protected function on_2_start(): void
    {
        $names = $this->sconcurCollection->createIndexes([
            ['keys' => ['field_a' => 1, 'field_b' => 1], 'name' => 'compound_ab'],
        ]);

        self::assertCount(1, $names);
        self::assertEquals('compound_ab', $names[0]);
    }

    protected function on_2_middle(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();
        self::assertGreaterThanOrEqual(4, count($indexes));
    }

    protected function on_iterate(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();
        self::assertGreaterThanOrEqual(1, count($indexes));
    }

    protected function on_exception(): void
    {
        // Invalid index spec
        $this->sconcurCollection->createIndexes([
            ['keys' => ['$invalid' => 'bad_type']],
        ]);
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
