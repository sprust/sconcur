<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Admin;

use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbListDatabasesTest extends BaseTestCase
{
    public function test(): void
    {
        $database = TestMongodbResolver::getSconcurTestDatabase();

        // Ensure the database exists by writing into one of its collections.
        $database->selectCollection('listDatabases')->insertOne(['x' => 1]);

        $names = $database->client->listDatabases();

        self::assertContains($database->name, $names);
    }
}
