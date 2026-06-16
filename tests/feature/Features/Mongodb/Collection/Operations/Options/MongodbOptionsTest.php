<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Options;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\WaitGroup;
use Throwable;

class MongodbOptionsTest extends BaseTestCase
{
    /** @var array<string, mixed> */
    private const array CASE_INSENSITIVE = ['locale' => 'en', 'strength' => 2];

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = TestMongodbResolver::getSconcurTestCollection(
            collectionName: 'options',
        );

        $this->collection->deleteMany(
            filter: [],
        );
    }

    // --- arrayFilters ---

    public function testUpdateOneArrayFilters(): void
    {
        $this->collection->insertOne([
            '_id'   => 'doc1',
            'items' => [
                ['name' => 'a', 'done' => false],
                ['name' => 'b', 'done' => false],
                ['name' => 'a', 'done' => false],
            ],
        ]);

        $this->collection->updateOne(
            filter: ['_id' => 'doc1'],
            update: ['$set' => ['items.$[elem].done' => true]],
            arrayFilters: [['elem.name' => 'a']],
        );

        $document = $this->collection->findOne(
            filter: ['_id' => 'doc1'],
        );

        self::assertNotNull($document);
        self::assertTrue($document['items'][0]['done']);
        self::assertFalse($document['items'][1]['done']);
        self::assertTrue($document['items'][2]['done']);
    }

    public function testUpdateManyArrayFilters(): void
    {
        $this->collection->insertMany([
            ['_id' => 'd1', 'items' => [['n' => 'a', 'v' => 0], ['n' => 'b', 'v' => 0]]],
            ['_id' => 'd2', 'items' => [['n' => 'a', 'v' => 0]]],
        ]);

        $result = $this->collection->updateMany(
            filter: [],
            update: ['$set' => ['items.$[elem].v' => 1]],
            arrayFilters: [['elem.n' => 'a']],
        );

        self::assertSame(2, $result->modifiedCount);

        $d1 = $this->collection->findOne(
            filter: ['_id' => 'd1'],
        );
        self::assertNotNull($d1);
        self::assertSame(1, $d1['items'][0]['v']);
        self::assertSame(0, $d1['items'][1]['v']);
    }

    public function testFindOneAndUpdateArrayFilters(): void
    {
        $this->collection->insertOne([
            '_id'   => 'doc1',
            'items' => [['n' => 'a', 'v' => 0], ['n' => 'b', 'v' => 0]],
        ]);

        $document = $this->collection->findOneAndUpdate(
            filter: ['_id' => 'doc1'],
            update: ['$set' => ['items.$[elem].v' => 7]],
            returnDocument: true,
            arrayFilters: [['elem.n' => 'a']],
        );

        self::assertNotNull($document);
        self::assertSame(7, $document['items'][0]['v']);
        self::assertSame(0, $document['items'][1]['v']);
    }

    // --- collation ---

    public function testFindOneCollation(): void
    {
        $this->collection->insertOne(['_id' => 'c1', 'name' => 'Alice']);

        self::assertNull(
            $this->collection->findOne(
                filter: ['name' => 'alice'],
            ),
        );

        $found = $this->collection->findOne(
            filter: ['name' => 'alice'],
            collation: self::CASE_INSENSITIVE,
        );

        self::assertNotNull($found);
        self::assertSame('Alice', $found['name']);
    }

    public function testUpdateManyCollation(): void
    {
        $this->collection->insertMany([
            ['name' => 'Alice'],
            ['name' => 'alice'],
            ['name' => 'Bob'],
        ]);

        $result = $this->collection->updateMany(
            filter: ['name' => 'alice'],
            update: ['$set' => ['matched' => true]],
            collation: self::CASE_INSENSITIVE,
        );

        self::assertSame(2, $result->modifiedCount);
    }

    public function testDeleteManyCollation(): void
    {
        $this->collection->insertMany([
            ['name' => 'Alice'],
            ['name' => 'alice'],
            ['name' => 'Bob'],
        ]);

        $result = $this->collection->deleteMany(
            filter: ['name' => 'alice'],
            collation: self::CASE_INSENSITIVE,
        );

        self::assertSame(2, $result->deletedCount);
    }

    public function testDistinctCollation(): void
    {
        $this->collection->insertMany([
            ['t' => 'A'],
            ['t' => 'a'],
            ['t' => 'B'],
        ]);

        $values = $this->collection->distinct(
            fieldName: 't',
            filter: [],
            collation: self::CASE_INSENSITIVE,
        );

        // "A" and "a" collapse under a case-insensitive collation.
        self::assertCount(2, $values);
    }

    // --- hint ---

    public function testUpdateOneHint(): void
    {
        $this->collection->insertMany([['k' => 1], ['k' => 2]]);
        $this->collection->createIndex(
            keys: ['k' => 1],
        );

        $result = $this->collection->updateOne(
            filter: ['k' => 1],
            update: ['$set' => ['touched' => true]],
            hint: ['k' => 1],
        );

        self::assertSame(1, $result->modifiedCount);
    }

    public function testFindOneHint(): void
    {
        $this->collection->insertOne(['k' => 5]);
        $this->collection->createIndex(
            keys: ['k' => 1],
        );

        $found = $this->collection->findOne(
            filter: ['k' => 5],
            hint: ['k' => 1],
        );

        self::assertNotNull($found);
        self::assertSame(5, $found['k']);
    }

    public function testDeleteOneHint(): void
    {
        $this->collection->insertMany([['k' => 1], ['k' => 2]]);
        $this->collection->createIndex(
            keys: ['k' => 1],
        );

        $result = $this->collection->deleteOne(
            filter: ['k' => 1],
            hint: ['k' => 1],
        );

        self::assertSame(1, $result->deletedCount);
    }

    public function testFindWithNonexistentHintFails(): void
    {
        $this->collection->insertOne(['k' => 1]);

        $this->expectException(Throwable::class);

        iterator_to_array(
            $this->collection->find(
                filter: ['k' => 1],
                hint: 'nonexistent_index',
            ),
        );
    }

    // --- options under async execution ---

    public function testOptionsWorkUnderAsync(): void
    {
        $this->collection->insertOne([
            '_id'   => 'async1',
            'items' => [['n' => 'a', 'v' => 0], ['n' => 'b', 'v' => 0]],
        ]);

        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function (): void {
            $this->collection->updateOne(
                filter: ['_id' => 'async1'],
                update: ['$set' => ['items.$[elem].v' => 9]],
                arrayFilters: [['elem.n' => 'a']],
            );
        });

        $waitGroup->waitAll();

        $document = $this->collection->findOne(
            filter: ['_id' => 'async1'],
        );

        self::assertNotNull($document);
        self::assertSame(9, $document['items'][0]['v']);
        self::assertSame(0, $document['items'][1]['v']);
    }
}
