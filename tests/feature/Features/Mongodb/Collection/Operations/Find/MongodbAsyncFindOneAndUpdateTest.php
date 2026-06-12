<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Find;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncFindOneAndUpdateTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->sconcurCollection->insertOne([$this->fieldName => 'original']);
    }

    protected function getCollectionName(): string
    {
        return 'findOneAndUpdate';
    }

    protected function on_1_start(): void
    {
        $result = $this->sconcurCollection->findOneAndUpdate(
            filter: [$this->fieldName => 'original'],
            update: ['$set' => [$this->fieldName => 'updated']],
            returnDocument: true,
        );

        self::assertNotNull($result);
        self::assertEquals('updated', $result[$this->fieldName]);
    }

    protected function on_1_middle(): void
    {
        $result = $this->sconcurCollection->findOneAndUpdate(
            filter: [$this->fieldName => 'updated'],
            update: ['$set' => [$this->fieldName => 'updated2']],
            returnDocument: false,
        );

        self::assertNotNull($result);
        self::assertEquals('updated', $result[$this->fieldName]);
    }

    protected function on_2_start(): void
    {
        $result = $this->sconcurCollection->findOneAndUpdate(
            filter: [$this->fieldName => 'nonexistent'],
            update: ['$set' => [$this->fieldName => 'upserted']],
            upsert: true,
            returnDocument: true,
        );

        self::assertNotNull($result);
        self::assertEquals('upserted', $result[$this->fieldName]);
    }

    protected function on_2_middle(): void
    {
        $result = $this->sconcurCollection->findOneAndUpdate(
            filter: [$this->fieldName => 'notfound_ever'],
            update: ['$set' => [$this->fieldName => 'x']],
        );

        self::assertNull($result);
    }

    protected function on_iterate(): void
    {
        $count = $this->sconcurCollection->countDocuments([]);
        self::assertGreaterThan(0, $count);
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->findOneAndUpdate(
            filter: [],
            update: [uniqid('$') => ['x' => 1]],
        );
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
