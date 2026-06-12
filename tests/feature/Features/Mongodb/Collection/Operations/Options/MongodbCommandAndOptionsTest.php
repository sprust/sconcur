<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Options;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use Throwable;

class MongodbCommandAndOptionsTest extends BaseTestCase
{
    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = TestMongodbResolver::getSconcurTestCollection('options');

        $this->collection->deleteMany([]);
    }

    public function testDatabaseCommand(): void
    {
        $result = $this->collection->database->command(['ping' => 1]);

        self::assertSame(1.0, $result['ok']);
    }

    public function testUpdateWithArrayFilters(): void
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

        $document = $this->collection->findOne(['_id' => 'doc1']);

        self::assertNotNull($document);
        self::assertTrue($document['items'][0]['done']);
        self::assertFalse($document['items'][1]['done']);
        self::assertTrue($document['items'][2]['done']);
    }

    public function testFindOneWithCaseInsensitiveCollation(): void
    {
        $this->collection->insertOne(['_id' => 'c1', 'name' => 'Alice']);

        // Different case does not match without collation.
        self::assertNull($this->collection->findOne(['name' => 'alice']));

        // Case-insensitive collation (strength 2) matches.
        $found = $this->collection->findOne(
            filter: ['name' => 'alice'],
            collation: ['locale' => 'en', 'strength' => 2],
        );

        self::assertNotNull($found);
        self::assertSame('Alice', $found['name']);
    }

    public function testDeleteWithHint(): void
    {
        $this->collection->insertMany([
            ['k' => 1],
            ['k' => 2],
        ]);

        $this->collection->createIndex(['k' => 1]);

        $result = $this->collection->deleteOne(['k' => 1], hint: ['k' => 1]);

        self::assertSame(1, $result->deletedCount);
    }

    public function testFindWithNonexistentHintFails(): void
    {
        $this->collection->insertOne(['k' => 1]);

        $this->expectException(Throwable::class);

        iterator_to_array(
            $this->collection->find(['k' => 1], hint: 'nonexistent_index')
        );
    }
}
