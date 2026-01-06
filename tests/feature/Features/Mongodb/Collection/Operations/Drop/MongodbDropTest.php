<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Drop;

use SConcur\Entities\Context;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbDropTest extends BaseTestCase
{
    public function test(): void
    {
        $context = Context::create(2);

        $collectionName = 'drop';

        $sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $sconcurCollection->insertOne(
            context: $context,
            document: [uniqid() => uniqid()]
        );

        self::assertCollectionNames($collectionName, 1);

        self::assertEquals(
            1,
            $sconcurCollection->countDocuments($context, []) > 0
        );

        $sconcurCollection->drop($context);

        self::assertCollectionNames($collectionName, 0);
    }

    protected static function assertCollectionNames(string $collectionName, int $expectedCount): void
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
