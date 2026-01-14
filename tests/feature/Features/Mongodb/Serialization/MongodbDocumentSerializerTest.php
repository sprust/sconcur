<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Serialization;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbDocumentSerializerTest extends BaseTestCase
{
    protected Collection $sconcurCollection;

    protected int $documentsCount = 10;

    protected int $intValue     = 123;
    protected float $floatValue = 123.456;
    protected bool $boolValue   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection('serializer');

        $this->sconcurCollection->deleteMany([]);

        $this->sconcurCollection->insertMany(
            documents: array_map(
                fn(int $index) => [
                    'objectId' => TestMongodbResolver::getSconcurObjectId(),
                    'date'     => TestMongodbResolver::getSconcurDateTime(),
                    'int'      => $this->intValue,
                    'float'    => $this->floatValue,
                    'bool'     => $this->boolValue,
                ],
                range(1, $this->documentsCount)
            )
        );
    }

    public function testFindOne(): void
    {
        $document = $this->sconcurCollection->findOne([]);

        self::assertNotNull($document);

        $objectId = $document['objectId'];

        self::assertNotNull($objectId);

        self::assertTrue(
            $objectId instanceof ObjectId
        );

        $date = $document['date'];

        self::assertNotNull($date);

        self::assertTrue(
            $date instanceof UTCDateTime
        );

        self::assertSame(
            $this->intValue,
            $document['int']
        );

        self::assertSame(
            $this->floatValue,
            $document['float']
        );

        self::assertSame(
            $this->boolValue,
            $document['bool']
        );
    }

    public function testAggregateGroup(): void
    {
        $iterator = $this->sconcurCollection->aggregate([
            [
                '$group' => [
                    '_id'   => null,
                    'count' => [
                        '$sum' => 1,
                    ],
                ],
            ],
        ]);

        $items = iterator_to_array($iterator);

        self::assertCount(
            1,
            $items
        );

        $item = $items[0];

        self::assertSame(
            $this->documentsCount,
            $item['count']
        );
    }
}
