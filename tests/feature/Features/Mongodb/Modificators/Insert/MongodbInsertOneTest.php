<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Modificators\Insert;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbTestCase;
use Throwable;

class MongodbInsertOneTest extends BaseMongodbTestCase
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

    protected function on_1_start(Context $context): void
    {
        $insertResult = $this->feature->insertOne(
            context: Context::create(2),
            document: [$this->fieldName => $this->fieldValue]
        );

        self::assertTrue(
            $insertResult->insertedId instanceof ObjectId
        );
    }

    protected function on_1_middle(Context $context): void
    {
        $insertResult = $this->feature->insertOne(
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
        $this->feature->insertOne(
            context: Context::create(2),
            document: [$this->fieldName => $this->fieldValue]
        );

        $exception = null;

        try {
            $this->feature->insertOne(
                context: Context::create(2),
                document: [[$this->fieldName => $this->fieldValue]]
            );
        } catch (Throwable $exception) {
            //
        }

        self::assertMongodbException($exception);
    }

    protected function assertResult(array $results): void
    {
        self::assertEquals(
            6,
            $this->driverCollection->countDocuments([$this->fieldName => $this->driverDateTime])
        );
    }
}
