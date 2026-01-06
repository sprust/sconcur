<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Insert;

use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbAsyncInsertOneTest extends BaseMongodbAsyncTestCase
{
    protected \MongoDB\BSON\UTCDateTime $driverDateTime;

    private string $fieldName;
    private UTCDateTime $fieldValue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverDateTime = TestMongodbResolver::getDriverDateTime();

        $this->fieldName  = uniqid();
        $this->fieldValue = TestMongodbResolver::getSconcurDateTime(
            $this->driverDateTime->toDateTime()
        );
    }

    protected function getCollectionName(): string
    {
        return 'insertOne';
    }

    protected function on_1_start(): void
    {
        $insertResult = $this->sconcurCollection->insertOne(
            document: [$this->fieldName => $this->fieldValue]
        );

        self::assertTrue(
            $insertResult->insertedId instanceof ObjectId
        );
    }

    protected function on_1_middle(): void
    {
        $insertResult = $this->sconcurCollection->insertOne(
            document: [$this->fieldName => $this->fieldValue]
        );

        self::assertTrue(
            $insertResult->insertedId instanceof ObjectId
        );
    }

    protected function on_2_start(): void
    {
        $this->on_1_start();
    }

    protected function on_2_middle(): void
    {
        $this->on_1_middle();
    }

    protected function on_iterate(): void
    {
        $this->sconcurCollection->insertOne(
            document: [$this->fieldName => $this->fieldValue]
        );
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->insertOne(
            document: [[$this->fieldName => $this->fieldValue]]
        );
    }

    protected function assertResult(array $results): void
    {
        self::assertEquals(
            6,
            $this->driverCollection->countDocuments([$this->fieldName => $this->driverDateTime])
        );
    }
}
