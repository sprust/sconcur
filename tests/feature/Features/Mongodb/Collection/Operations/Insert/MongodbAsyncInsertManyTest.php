<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Insert;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncInsertManyTest extends BaseMongodbAsyncTestCase
{
    protected \MongoDB\BSON\ObjectId $driverObjectId;

    protected string $fieldName;

    protected int $documentsCount;
    protected int $expectedDocumentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->documentsCount         = 3;
        $this->expectedDocumentsCount = 18;
    }

    protected function getCollectionName(): string
    {
        return 'insertMany';
    }

    protected function on_1_start(): void
    {
        $this->insertDocuments();
    }

    protected function on_1_middle(): void
    {
        $this->insertDocuments();
    }

    protected function on_2_start(): void
    {
        $this->insertDocuments();
    }

    protected function on_2_middle(): void
    {
        $this->insertDocuments();
    }

    protected function on_iterate(): void
    {
        $this->insertDocuments();
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->insertMany(
            /** @phpstan-ignore-next-line argument.type */
            documents: [$this->fieldName => $this->sconcurObjectId]
        );
    }

    protected function assertResult(array $results): void
    {
        self::assertEquals(
            $this->expectedDocumentsCount,
            $this->driverCollection->countDocuments([$this->fieldName => $this->driverObjectId])
        );
    }

    protected function insertDocuments(): void
    {
        $insertResult = $this->sconcurCollection->insertMany(
            documents: array_map(
                fn() => [$this->fieldName => $this->sconcurObjectId],
                range(1, $this->documentsCount)
            )
        );

        self::assertEquals(
            $this->documentsCount,
            $insertResult->insertedCount
        );
    }
}
