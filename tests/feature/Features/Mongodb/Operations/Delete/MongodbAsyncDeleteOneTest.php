<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Operations\Delete;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbAsyncTestCase;

class MongodbAsyncDeleteOneTest extends BaseMongodbAsyncTestCase
{
    protected \MongoDB\BSON\ObjectId $driverObjectId;

    protected string $fieldName;
    protected ObjectId $objectId;

    protected int $documentsCount;
    protected int $deletedDocumentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverObjectId = new \MongoDB\BSON\ObjectId('693a7119e9d4885085366c80');

        $this->fieldName = uniqid();
        $this->objectId  = new ObjectId('693a7119e9d4885085366c80');

        $this->documentsCount        = 10;
        $this->deletedDocumentsCount = 0;

        $this->driverCollection->insertMany(
            array_map(
                fn(int $index) => [
                    $this->fieldName => $this->driverObjectId,
                ],
                range(1, $this->documentsCount)
            )
        );
    }

    protected function getCollectionName(): string
    {
        return 'deleteOne';
    }

    protected function on_1_start(Context $context): void
    {
        $this->deleteOne($context);
    }

    protected function on_1_middle(Context $context): void
    {
        $this->deleteOne($context);
    }

    protected function on_2_start(Context $context): void
    {
        $result = $this->sconcurCollection->deleteOne(
            context: $context,
            filter: [
                uniqid() => $this->objectId,
            ]
        );

        self::assertEquals(
            0,
            $result->deletedCount
        );
    }

    protected function on_2_middle(Context $context): void
    {
        $this->deleteOne($context);
    }

    protected function on_iterate(Context $context): void
    {
        $this->deleteOne($context);
    }

    protected function on_exception(Context $context): void
    {
        $this->sconcurCollection->deleteOne($context, ['$set' => 11]);
    }

    protected function assertResult(array $results): void
    {
        self::assertEquals(
            $this->documentsCount - $this->deletedDocumentsCount,
            $this->driverCollection->countDocuments()
        );
    }

    protected function deleteOne(Context $context): void
    {
        $result = $this->sconcurCollection->deleteOne(
            context: $context,
            filter: [
                $this->fieldName => $this->objectId,
            ]
        );

        self::assertEquals(
            1,
            $result->deletedCount
        );

        ++$this->deletedDocumentsCount;
    }
}
