<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Operations\Insert;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbAsyncTestCase;

class MongodbAsyncInsertOneTest extends BaseMongodbAsyncTestCase
{
    protected \MongoDB\BSON\UTCDateTime $driverDateTime;

    private string $fieldName;
    private UTCDateTime $fieldValue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverDateTime = new \MongoDB\BSON\UTCDateTime();

        $this->fieldName  = uniqid();
        $this->fieldValue = new UTCDateTime($this->driverDateTime->toDateTime());
    }

    protected function getCollectionName(): string
    {
        return 'insertOne';
    }

    protected function on_1_start(Context $context): void
    {
        $insertResult = $this->sconcurCollection->insertOne(
            context: Context::create(2),
            document: [$this->fieldName => $this->fieldValue]
        );

        self::assertTrue(
            $insertResult->insertedId instanceof ObjectId
        );
    }

    protected function on_1_middle(Context $context): void
    {
        $insertResult = $this->sconcurCollection->insertOne(
            context: Context::create(2),
            document: [$this->fieldName => $this->fieldValue]
        );

        self::assertTrue(
            $insertResult->insertedId instanceof ObjectId
        );
    }

    protected function on_2_start(Context $context): void
    {
        $this->on_1_start($context);
    }

    protected function on_2_middle(Context $context): void
    {
        $this->on_1_middle($context);
    }

    protected function on_iterate(Context $context): void
    {
        $this->sconcurCollection->insertOne(
            context: Context::create(2),
            document: [$this->fieldName => $this->fieldValue]
        );
    }

    protected function on_exception(Context $context): void
    {
        $this->sconcurCollection->insertOne(
            context: Context::create(2),
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
