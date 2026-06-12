<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Count;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbCountDocumentsTest extends BaseTestCase
{
    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = TestMongodbResolver::getSconcurTestCollection('countDocuments');

        $this->collection->deleteMany([]);

        $this->collection->insertMany([
            ['k' => 1],
            ['k' => 2],
            ['k' => 1],
        ]);
    }

    public function testCountsAllWithEmptyFilter(): void
    {
        self::assertSame(3, $this->collection->countDocuments([]));
    }

    public function testCountsMatchingFilter(): void
    {
        self::assertSame(2, $this->collection->countDocuments(['k' => 1]));
    }

    public function testCountsZeroWhenNoMatch(): void
    {
        self::assertSame(0, $this->collection->countDocuments(['k' => 99]));
    }
}
