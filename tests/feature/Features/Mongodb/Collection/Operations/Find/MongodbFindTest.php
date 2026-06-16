<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Find;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\WaitGroup;

class MongodbFindTest extends BaseTestCase
{
    protected Collection $sconcurCollection;
    protected string $fieldName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $collectionName = 'op_Find';

        $driverCollection = TestMongodbResolver::getDriverTestCollection($collectionName);
        $driverCollection->deleteMany([]);

        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);
    }

    public function testBasicFind(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function () {
            $this->sconcurCollection->insertMany(
                array_map(
                    fn(int $index) => [$this->fieldName => $index],
                    range(1, 10),
                ),
            );

            $results = iterator_to_array(
                $this->sconcurCollection->find(
                    filter: [$this->fieldName => ['$exists' => true]],
                    batchSize: 3,
                ),
            );

            self::assertCount(10, $results);
        });

        $waitGroup->waitAll();
    }

    public function testFindWithLimit(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function () {
            $this->sconcurCollection->insertMany(
                array_map(
                    fn(int $index) => [$this->fieldName => $index],
                    range(1, 10),
                ),
            );

            $results = iterator_to_array(
                $this->sconcurCollection->find(
                    filter: [$this->fieldName => ['$exists' => true]],
                    limit: 5,
                    batchSize: 2,
                ),
            );

            self::assertCount(5, $results);
        });

        $waitGroup->waitAll();
    }

    public function testFindWithSort(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function () {
            $this->sconcurCollection->insertMany(
                array_map(
                    fn(int $index) => [$this->fieldName => $index],
                    range(1, 5),
                ),
            );

            $results = iterator_to_array(
                $this->sconcurCollection->find(
                    filter: [$this->fieldName => ['$exists' => true]],
                    sort: [$this->fieldName => -1],
                ),
            );

            self::assertCount(5, $results);
            self::assertEquals(5, $results[0][$this->fieldName]);
            self::assertEquals(1, $results[4][$this->fieldName]);
        });

        $waitGroup->waitAll();
    }
}
