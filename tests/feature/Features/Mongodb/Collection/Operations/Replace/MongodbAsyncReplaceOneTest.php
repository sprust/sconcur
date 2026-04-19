<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Replace;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncReplaceOneTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;
    protected string $marker1;
    protected string $marker2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();
        $this->marker1   = uniqid('m1_');
        $this->marker2   = uniqid('m2_');

        $this->sconcurCollection->insertMany([
            [$this->fieldName => $this->marker1, 'extra' => 1],
            [$this->fieldName => $this->marker2, 'extra' => 2],
        ]);
    }

    protected function getCollectionName(): string
    {
        return 'replaceOne';
    }

    protected function on_1_start(): void
    {
        $result = $this->sconcurCollection->replaceOne(
            filter: [$this->fieldName => $this->marker1],
            replacement: [$this->fieldName => $this->marker1, 'replaced' => true],
        );

        self::assertEquals(1, $result->matchedCount);
        self::assertEquals(1, $result->modifiedCount);
    }

    protected function on_1_middle(): void
    {
        $result = $this->sconcurCollection->replaceOne(
            filter: [$this->fieldName => 'nonexistent_' . uniqid()],
            replacement: [$this->fieldName => 'upserted'],
            upsert: true,
        );

        self::assertEquals(0, $result->matchedCount);
        self::assertEquals(1, $result->upsertedCount);
    }

    protected function on_2_start(): void
    {
        $result = $this->sconcurCollection->replaceOne(
            filter: [$this->fieldName => $this->marker2],
            replacement: [$this->fieldName => $this->marker2, 'step' => 2],
        );

        self::assertEquals(1, $result->matchedCount);
    }

    protected function on_2_middle(): void
    {
        $count = $this->sconcurCollection->countDocuments([]);
        self::assertGreaterThan(0, $count);
    }

    protected function on_iterate(): void
    {
        $count = $this->sconcurCollection->countDocuments([]);
        self::assertGreaterThan(0, $count);
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->replaceOne(
            filter: [],
            replacement: ['$set' => ['x' => 1]],
        );
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
