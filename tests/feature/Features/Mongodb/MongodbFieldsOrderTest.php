<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbFieldsOrderTest extends BaseTestCase
{
    protected Collection $sconcurCollection;

    protected int $keysCount = 100;

    /**
     * @var string[]
     */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection('fieldsOrder');

        $this->sconcurCollection->deleteMany([]);

        $this->keys = array_map(
            fn(int $index) => '_' . $index,
            range(1, $this->keysCount)
        );

        $document = [];

        foreach ($this->keys as $key) {
            $document[$key] = true;
        }

        $this->sconcurCollection->insertOne(
            document: $document
        );
    }

    public function test(): void
    {
        $document = $this->sconcurCollection->findOne(
            filter: [],
            projection: [
                '_id' => 0,
            ]
        );

        self::assertNotNull($document);

        self::assertCount(
            $this->keysCount,
            $document
        );

        $documentKeys = array_keys($document);

        foreach ($this->keys as $index => $key) {
            self::assertSame(
                $key,
                $documentKeys[$index],
                "Failed asserting key [$key] at index [$index]"
            );
        }
    }
}
