<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use Throwable;

class MongodbInsertTest extends BaseMongodbTestCase
{
    public function testInsertOne(): void
    {
        $driverDateTime = new \MongoDB\BSON\UTCDateTime();

        $fieldName  = uniqid();
        $fieldValue = new UTCDateTime($driverDateTime->toDateTime());

        $insertResult = $this->feature->insertOne(
            context: Context::create(1000),
            document: [$fieldName => $fieldValue]
        );

        self::assertEquals(
            1,
            $insertResult->getInsertedCount()
        );
        self::assertEquals(
            1,
            $this->driverCollection->countDocuments([$fieldName => $driverDateTime])
        );

        $exception = null;

        try {
            $this->feature->insertOne(
                context: Context::create(1000),
                document: [[$fieldName => $fieldValue]]
            );
        } catch (Throwable $exception) {
            //
        }

        self::assertMongodbException($exception);
    }

    public function testInsertMany(): void
    {
        $driverObjectId = new \MongoDB\BSON\ObjectId('693a7119e9d4885085366c80');

        $fieldName  = uniqid();
        $fieldValue = new ObjectId('693a7119e9d4885085366c80');

        $count = 3;

        $insertResult = $this->feature->insertMany(
            context: Context::create(1000),
            documents: array_map(
                static fn() => [$fieldName => $fieldValue],
                range(1, $count)
            )
        );

        self::assertEquals(
            $count,
            $insertResult->getInsertedCount()
        );
        self::assertEquals(
            $count,
            $this->driverCollection->countDocuments([$fieldName => $driverObjectId])
        );

        $exception = null;

        try {
            $this->feature->insertMany(
                context: Context::create(1000),
                /** @phpstan-ignore-next-line argument.type */
                documents: [$fieldName => $driverObjectId]
            );
        } catch (Throwable $exception) {
            //
        }

        self::assertMongodbException($exception);
    }
}
