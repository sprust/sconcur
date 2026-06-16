<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Serialization;

use DateTime;
use DateTimeZone;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Int64;
use MongoDB\BSON\Javascript;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\UTCDateTime;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbDocumentSerializerTest extends BaseTestCase
{
    protected Collection $sconcurCollection;

    protected int $documentsCount = 10;

    protected int $intValue     = 123;
    protected float $floatValue = 123.456;
    protected bool $boolValue   = true;

    // Whole-second timestamp (zero milliseconds): the extension must serialize it back
    // as "...:55.000Z", not "...:55Z", otherwise PHP parsing fails. Regression guard.
    protected string $zeroMsDateString = '2026-06-12 06:44:55';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection(
            collectionName: 'serializer',
        );

        $this->sconcurCollection->deleteMany(
            filter: [],
        );

        $this->sconcurCollection->insertMany(
            documents: array_map(
                fn(int $index) => [
                    'objectId'   => TestMongodbResolver::getSconcurObjectId(),
                    'date'       => TestMongodbResolver::getSconcurDateTime(),
                    'dateZeroMs' => TestMongodbResolver::getSconcurDateTime(
                        dateTime: new DateTime($this->zeroMsDateString, new DateTimeZone('UTC')),
                    ),
                    'int'        => $this->intValue,
                    'float'      => $this->floatValue,
                    'bool'       => $this->boolValue,
                ],
                range(1, $this->documentsCount)
            )
        );
    }

    public function testFindOne(): void
    {
        $document = $this->sconcurCollection->findOne(
            filter: [],
        );

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

        $dateZeroMs = $document['dateZeroMs'];

        self::assertNotNull($dateZeroMs);

        self::assertTrue(
            $dateZeroMs instanceof UTCDateTime
        );

        self::assertSame(
            $this->zeroMsDateString,
            $dateZeroMs->toDateTime()->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '000000',
            $dateZeroMs->toDateTime()->format('u')
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

    /**
     * Every BSON type must round-trip unchanged through the full path:
     * PHP (ext-mongodb encode) → Go (raw BSON) → MongoDB → Go (raw BSON) → PHP decode.
     */
    public function testRoundTripsAllBsonTypes(): void
    {
        $objectId = new ObjectId('6919e3d1a3673d3f4d9137a3');

        $document = [
            'int32'      => 123,
            'int64'      => 9_000_000_000,
            'double'     => 123.456,
            'string'     => 'hello',
            'bool'       => true,
            'null'       => null,
            'objectId'   => $objectId,
            'date'       => new UTCDateTime(1_700_000_000_000),
            'binary'     => new Binary('binary-data', Binary::TYPE_GENERIC),
            'regex'      => new Regex('^abc', 'i'),
            'timestamp'  => new Timestamp(1, 1_700_000_000),
            'decimal128' => new Decimal128('3.14159'),
            'minKey'     => new MinKey(),
            'maxKey'     => new MaxKey(),
            'javascript' => new Javascript('function () { return 1; }'),
            'document'   => ['nested' => 'value', 'number' => 7],
            'array'      => [1, 2, 3],
            'objectIds'  => [$objectId],
        ];

        $insertResult = $this->sconcurCollection->insertOne($document);

        $found = $this->sconcurCollection->findOne(
            filter: ['_id' => $insertResult->insertedId],
        );

        self::assertNotNull($found);

        self::assertSame(123, $found['int32']);

        self::assertInstanceOf(Int64::class, $found['int64']);
        self::assertSame('9000000000', (string) $found['int64']);

        self::assertSame(123.456, $found['double']);
        self::assertSame('hello', $found['string']);
        self::assertTrue($found['bool']);
        self::assertNull($found['null']);

        self::assertInstanceOf(ObjectId::class, $found['objectId']);
        self::assertSame((string) $objectId, (string) $found['objectId']);

        self::assertInstanceOf(UTCDateTime::class, $found['date']);
        self::assertSame('1700000000000', (string) $found['date']);

        self::assertInstanceOf(Binary::class, $found['binary']);
        self::assertSame('binary-data', $found['binary']->getData());
        self::assertSame(Binary::TYPE_GENERIC, $found['binary']->getType());

        self::assertInstanceOf(Regex::class, $found['regex']);
        self::assertSame('^abc', $found['regex']->getPattern());
        self::assertSame('i', $found['regex']->getFlags());

        self::assertInstanceOf(Timestamp::class, $found['timestamp']);
        self::assertSame(1, $found['timestamp']->getIncrement());
        self::assertSame(1_700_000_000, $found['timestamp']->getTimestamp());

        self::assertInstanceOf(Decimal128::class, $found['decimal128']);
        self::assertSame('3.14159', (string) $found['decimal128']);

        self::assertInstanceOf(MinKey::class, $found['minKey']);
        self::assertInstanceOf(MaxKey::class, $found['maxKey']);

        self::assertInstanceOf(Javascript::class, $found['javascript']);
        self::assertSame('function () { return 1; }', $found['javascript']->getCode());

        self::assertSame(['nested' => 'value', 'number' => 7], $found['document']);
        self::assertSame([1, 2, 3], $found['array']);

        self::assertInstanceOf(ObjectId::class, $found['objectIds'][0]);
        self::assertSame((string) $objectId, (string) $found['objectIds'][0]);
    }

    /**
     * The cursor batch path (find/aggregate → unserializeBatch wrapper) must decode BSON
     * types with the same fidelity as the single-document path.
     */
    public function testRoundTripsBsonTypesViaCursorBatch(): void
    {
        $objectId = new ObjectId('6919e3d1a3673d3f4d9137a3');

        $this->sconcurCollection->insertOne([
            '_id'        => 'batch-types',
            'objectId'   => $objectId,
            'date'       => new UTCDateTime(1_700_000_000_000),
            'binary'     => new Binary('bin', Binary::TYPE_GENERIC),
            'decimal128' => new Decimal128('2.5'),
            'document'   => ['nested' => 'value'],
            'array'      => [1, 2, 3],
        ]);

        $documents = iterator_to_array(
            $this->sconcurCollection->find(
                filter: ['_id' => 'batch-types'],
            )
        );

        self::assertCount(1, $documents);

        $found = $documents[0];

        self::assertInstanceOf(ObjectId::class, $found['objectId']);
        self::assertSame((string) $objectId, (string) $found['objectId']);
        self::assertInstanceOf(UTCDateTime::class, $found['date']);
        self::assertSame('1700000000000', (string) $found['date']);
        self::assertInstanceOf(Binary::class, $found['binary']);
        self::assertSame('bin', $found['binary']->getData());
        self::assertInstanceOf(Decimal128::class, $found['decimal128']);
        self::assertSame('2.5', (string) $found['decimal128']);
        self::assertSame(['nested' => 'value'], $found['document']);
        self::assertSame([1, 2, 3], $found['array']);
    }

    public function testAggregateGroup(): void
    {
        $iterator = $this->sconcurCollection->aggregate(
            pipeline: [
                [
                    '$group' => [
                        '_id'   => null,
                        'count' => [
                            '$sum' => 1,
                        ],
                    ],
                ],
            ],
        );

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
