<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Delete;

use SConcur\Entities\Context;
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

    protected function on_1_start(Context $context): void
    {
        $this->insertAndDelete($context);
    }

    protected function on_1_middle(Context $context): void
    {
        $this->insertAndDelete($context);
    }

    protected function on_2_start(Context $context): void
    {
        $result = $this->sconcurCollection->deleteMany(
            context: $context,
            filter: [
                uniqid() => $this->sconcurObjectId,
            ]
        );

        self::assertEquals(
            0,
            $result->deletedCount
        );
    }

    protected function on_2_middle(Context $context): void
    {
        $this->insertAndDelete($context);
    }

    protected function on_iterate(Context $context): void
    {
        $this->insertAndDelete($context);
    }

    protected function on_exception(Context $context): void
    {
        $this->sconcurCollection->deleteMany($context, ['$set' => 11]);
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

    protected function insertAndDelete(Context $context): void
    {
        $fieldName = uniqid("$this->fieldName-");

        $filter = [
            $fieldName => $this->sconcurObjectId,
        ];

        $this->sconcurCollection->insertMany(
            context: $context,
            documents: array_fill(
                start_index: 0,
                count: $this->documentsCount,
                value: $filter
            )
        );

        $this->deletedDocumentsCount += $this->sconcurCollection->deleteMany(
            context: $context,
            filter: $filter
        )->deletedCount;
    }
}
