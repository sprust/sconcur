<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Delete;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncDeleteManyTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected int $documentsCount;
    protected int $deletedDocumentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->documentsCount        = 10;
        $this->deletedDocumentsCount = 0;
    }

    protected function getCollectionName(): string
    {
        return 'deleteMany';
    }

    protected function on_1_start(): void
    {
        $this->insertAndDelete();
    }

    protected function on_1_middle(): void
    {
        $this->insertAndDelete();
    }

    protected function on_2_start(): void
    {
        $result = $this->sconcurCollection->deleteMany(
            filter: [
                uniqid() => $this->sconcurObjectId,
            ]
        );

        self::assertEquals(
            0,
            $result->deletedCount
        );
    }

    protected function on_2_middle(): void
    {
        $this->insertAndDelete();
    }

    protected function on_iterate(): void
    {
        $this->insertAndDelete();
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->deleteMany(['$set' => 11]);
    }

    protected function assertResult(array $results): void
    {
        self::assertEquals(
            $this->documentsCount * 5,
            $this->deletedDocumentsCount
        );

        self::assertEquals(
            0,
            $this->driverCollection->countDocuments()
        );
    }

    protected function insertAndDelete(): void
    {
        $fieldName = uniqid("$this->fieldName-");

        $filter = [
            $fieldName => $this->sconcurObjectId,
        ];

        $this->sconcurCollection->insertMany(
            documents: array_fill(
                start_index: 0,
                count: $this->documentsCount,
                value: $filter
            )
        );

        $this->deletedDocumentsCount += $this->sconcurCollection->deleteMany(
            filter: $filter
        )->deletedCount;
    }
}
