<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Modificators\Insert;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbTestCase;
use Throwable;

class MongodbInsertManyTest extends BaseMongodbTestCase
{
    protected \MongoDB\BSON\ObjectId $driverObjectId;

    protected string $fieldName;
    protected ObjectId $fieldValue;

    protected int $documentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverObjectId = new \MongoDB\BSON\ObjectId('693a7119e9d4885085366c80');

        $this->fieldName  = uniqid();
        $this->fieldValue = new ObjectId('693a7119e9d4885085366c80');

        $this->documentsCount = 3;
    }

    protected function on_1_start(Context $context): void
    {
        $this->insertDocuments();
    }

    protected function on_1_middle(Context $context): void
    {
        $this->insertDocuments();
    }

    protected function on_2_start(Context $context): void
    {
        $this->insertDocuments();
    }

    protected function on_2_middle(Context $context): void
    {
        $this->insertDocuments();
    }

    protected function on_iterate(Context $context): void
    {
        $this->insertDocuments();

        $exception = null;

        try {
            $this->feature->insertMany(
                context: Context::create(2),
                /** @phpstan-ignore-next-line argument.type */
                documents: [$this->fieldName => $this->fieldValue]
            );
        } catch (Throwable $exception) {
            //
        }

        self::assertMongodbException($exception);
    }

    protected function assertResult(array $results): void
    {
        self::assertEquals(
            $this->documentsCount * 6,
            $this->driverCollection->countDocuments([$this->fieldName => $this->driverObjectId])
        );
    }

    protected function insertDocuments(): void
    {
        $insertResult = $this->feature->insertMany(
            context: Context::create(2),
            documents: array_map(
                fn() => [$this->fieldName => $this->fieldValue],
                range(1, $this->documentsCount)
            )
        );

        self::assertEquals(
            $this->documentsCount,
            $insertResult->insertedCount
        );
    }
}
