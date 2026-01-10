<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Drop;

use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbDropTest extends BaseTestCase
{
    public function test(): void
    {
        $collectionName = 'drop';

        TestMongodbResolver::getDriverTestDatabase()->dropCollection($collectionName);

        self::assertCollectionCount($collectionName, 0);

        $sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $sconcurCollection->insertOne(
            document: [uniqid() => uniqid()]
        );

        self::assertCollectionCount($collectionName, 1);

        self::assertEquals(
            1,
            $sconcurCollection->countDocuments([]) > 0
        );

        $sconcurCollection->drop();

        self::assertCollectionCount($collectionName, 0);
    }

    protected static function assertCollectionCount(string $collectionName, int $expectedCount): void
    {
        self::assertEquals(
            $expectedCount,
            iterator_count(
                TestMongodbResolver::getDriverTestDatabase()->listCollectionNames([
                    'filter' => [
                        'name' => $collectionName,
                    ],
                ])
            )
        );
    }
}
