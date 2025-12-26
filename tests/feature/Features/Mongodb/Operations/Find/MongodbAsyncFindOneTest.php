<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Operations\Find;

use DateMalformedStringException;
use DateTime;
use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbAsyncTestCase;

class MongodbAsyncFindOneTest extends BaseMongodbAsyncTestCase
{
    protected \MongoDB\BSON\ObjectId $driverObjectId;

    protected string $fieldName;
    protected ObjectId $objectId;

    protected int $documentsCount;
    protected int $expectedDocumentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverObjectId = new \MongoDB\BSON\ObjectId('693a7119e9d4885085366c80');

        $this->fieldName = uniqid();
        $this->objectId  = new ObjectId('693a7119e9d4885085366c80');

        $this->documentsCount         = 3;
        $this->expectedDocumentsCount = 18;
    }

    protected function getCollectionName(): string
    {
        return 'findOne';
    }

    protected function on_1_start(Context $context): void
    {
        $result = $this->sconcurCollection->findOne($context, []);

        self::assertTrue(
            is_null($result)
        );
    }

    protected function on_1_middle(Context $context): void
    {
        $this->sconcurCollection->insertMany(
            $context,
            [
                [
                    uniqid() => uniqid(),
                ],
                [
                    $this->fieldName => $this->objectId,
                ],
            ]
        );

        $result = $this->sconcurCollection->findOne($context, [$this->fieldName => $this->objectId]);

        self::assertFalse(
            is_null($result)
        );

        self::assertArrayHasKey(
            $this->fieldName,
            $result
        );

        self::assertTrue(
            $result[$this->fieldName] instanceof ObjectId
        );

        self::assertEquals(
            $this->objectId,
            $result[$this->fieldName],
        );
    }

    /**
     * @throws DateMalformedStringException
     */
    protected function on_2_start(Context $context): void
    {
        $dateTime = new UTCDateTime(
            new DateTime()->modify('+1 day')
        );

        $this->sconcurCollection->insertMany(
            $context,
            [
                [
                    uniqid() => uniqid(),
                ],
                [
                    $this->fieldName => $dateTime,
                ],
            ]
        );

        $result = $this->sconcurCollection->findOne($context, [$this->fieldName => $dateTime]);

        self::assertFalse(
            is_null($result)
        );

        self::assertArrayHasKey(
            $this->fieldName,
            $result
        );

        self::assertTrue(
            $result[$this->fieldName] instanceof UTCDateTime
        );

        self::assertEquals(
            $dateTime,
            $result[$this->fieldName],
        );
    }

    protected function on_2_middle(Context $context): void
    {
        $result = $this->sconcurCollection->findOne($context, []);

        self::assertFalse(
            is_null($result)
        );
    }

    protected function on_iterate(Context $context): void
    {
        $result = $this->sconcurCollection->findOne($context, []);

        self::assertFalse(
            is_null($result)
        );
    }

    protected function on_exception(Context $context): void
    {
        $this->sconcurCollection->findOne($context, ['$set' => 11]);
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
