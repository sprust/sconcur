<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Admin;

use SConcur\Features\Mongodb\Connection\Database;
use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbAsyncListCollectionsTest extends BaseMongodbAsyncTestCase
{
    protected Database $sconcurDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sconcurDatabase = TestMongodbResolver::getSconcurTestDatabase();

        $this->sconcurCollection->insertOne(['test' => 1]);
    }

    protected function getCollectionName(): string
    {
        return 'listCollections';
    }

    protected function on_1_start(): void
    {
        $collections = $this->sconcurDatabase->listCollections();
        self::assertGreaterThan(0, count($collections));
    }

    protected function on_1_middle(): void
    {
        $collections = $this->sconcurDatabase->listCollections();
        self::assertGreaterThan(0, count($collections));
    }

    protected function on_2_start(): void
    {
        $collections = $this->sconcurDatabase->listCollections();

        $found = false;

        foreach ($collections as $name) {
            if (str_contains($name, 'ListCollections')) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Expected to find ListCollections collection in: ' . implode(', ', $collections));
    }

    protected function on_2_middle(): void
    {
        $collections = $this->sconcurDatabase->listCollections();
        self::assertGreaterThan(0, count($collections));
    }

    protected function on_iterate(): void
    {
        $collections = $this->sconcurDatabase->listCollections();
        self::assertGreaterThanOrEqual(0, count($collections));
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->findOne(
            filter: ['$set' => 11],
        );
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
