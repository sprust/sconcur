<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Find;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncFindOneAndDeleteTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->sconcurCollection->insertMany([
            [$this->fieldName => 'a'],
            [$this->fieldName => 'b'],
            [$this->fieldName => 'c'],
        ]);
    }

    protected function getCollectionName(): string
    {
        return 'findOneAndDelete';
    }

    protected function on_1_start(): void
    {
        $result = $this->sconcurCollection->findOneAndDelete(
            filter: [$this->fieldName => 'a'],
        );

        self::assertNotNull($result);
        self::assertEquals('a', $result[$this->fieldName]);
    }

    protected function on_1_middle(): void
    {
        $result = $this->sconcurCollection->findOneAndDelete(
            filter: [$this->fieldName => 'nonexistent'],
        );

        self::assertNull($result);
    }

    protected function on_2_start(): void
    {
        $result = $this->sconcurCollection->findOneAndDelete(
            filter: [$this->fieldName => 'b'],
        );

        self::assertNotNull($result);
        self::assertEquals('b', $result[$this->fieldName]);
    }

    protected function on_2_middle(): void
    {
        $count = $this->sconcurCollection->countDocuments(
            [$this->fieldName => ['$exists' => true]]
        );

        self::assertEquals(1, $count);
    }

    protected function on_iterate(): void
    {
        $count = $this->sconcurCollection->countDocuments([]);
        self::assertGreaterThanOrEqual(0, $count);
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->findOneAndDelete(
            filter: ['$set' => 11],
        );
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
