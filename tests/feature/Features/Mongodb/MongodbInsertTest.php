<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb;

use SConcur\Entities\Context;
use Throwable;

class MongodbInsertTest extends BaseMongodbTestCase
{
    public function testInsertOne(): void
    {
        $fieldName  = uniqid();
        $fieldValue = uniqid();

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
            $this->driverCollection->countDocuments([$fieldName => $fieldValue])
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
        $fieldName  = uniqid();
        $fieldValue = uniqid();

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
            $this->driverCollection->countDocuments([$fieldName => $fieldValue])
        );

        $exception = null;

        try {
            $this->feature->insertMany(
                context: Context::create(1000),
                /** @phpstan-ignore-next-line argument.type */
                documents: [$fieldName => $fieldValue]
            );
        } catch (Throwable $exception) {
            //
        }

        self::assertMongodbException($exception);
    }
}
