<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Find;

use DateMalformedStringException;
use DateTime;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbAsyncFindOneTest extends BaseMongodbAsyncTestCase
{
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
        return 'findOne';
    }

    protected function on_1_start(): void
    {
        $result = $this->sconcurCollection->findOne([]);

        self::assertTrue(
            is_null($result)
        );
    }

    protected function on_1_middle(): void
    {
        $this->sconcurCollection->insertMany(
            documents: [
                [
                    uniqid() => uniqid(),
                    uniqid() => uniqid(),
                ],
                [
                    $this->fieldName => $this->sconcurObjectId,
                    uniqid()         => uniqid(),
                ],
            ]
        );

        $result = $this->sconcurCollection->findOne(
            filter: [
                $this->fieldName => $this->sconcurObjectId,
            ]
        );

        self::assertFalse(
            is_null($result)
        );

        self::assertArrayHasKey(
            $this->fieldName,
            $result
        );

        self::assertCount(
            3,
            $result
        );

        self::assertTrue(
            $result[$this->fieldName] instanceof ObjectId
        );

        self::assertEquals(
            $this->sconcurObjectId,
            $result[$this->fieldName],
        );
    }

    /**
     * @throws DateMalformedStringException
     */
    protected function on_2_start(): void
    {
        $dateTime = TestMongodbResolver::getSconcurDateTime(
            new DateTime()->modify('+1 day')
        );

        $this->sconcurCollection->insertMany(
            documents: [
                [
                    uniqid() => uniqid(),
                    uniqid() => uniqid(),
                ],
                [
                    $this->fieldName => $dateTime,
                    uniqid()         => uniqid(),
                ],
            ]
        );

        $result = $this->sconcurCollection->findOne(
            filter: [
                $this->fieldName => $dateTime,
            ],
            projection: [
                $this->fieldName => 1,
            ]
        );

        self::assertFalse(
            is_null($result)
        );

        self::assertArrayHasKey(
            '_id',
            $result
        );

        self::assertCount(
            2,
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

    protected function on_2_middle(): void
    {
        $result = $this->sconcurCollection->findOne([]);

        self::assertFalse(
            is_null($result)
        );
    }

    protected function on_iterate(): void
    {
        $result = $this->sconcurCollection->findOne([]);

        self::assertFalse(
            is_null($result)
        );
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->findOne(['$set' => 11]);
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
