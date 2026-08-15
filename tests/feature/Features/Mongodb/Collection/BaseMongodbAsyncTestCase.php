<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use MongoDB\Collection as DriverCollection;
use SConcur\Features\Mongodb\Connection\Collection;
use MongoDB\BSON\ObjectId as DriverObjectId;
use SConcur\Bson\ObjectId;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use Throwable;

abstract class BaseMongodbAsyncTestCase extends BaseAsyncTestCase
{
    protected DriverCollection $driverCollection;
    protected Collection $sconcurCollection;

    protected DriverObjectId $driverObjectId;
    protected ObjectId $sconcurObjectId;

    abstract protected function getCollectionName(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $collectionName = 'async_' . ucfirst($this->getCollectionName());

        $this->driverCollection  = TestMongodbResolver::getDriverTestCollection($collectionName);
        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $this->driverObjectId  = TestMongodbResolver::getDriverObjectId();
        $this->sconcurObjectId = TestMongodbResolver::getSconcurObjectId();

        $this->driverCollection->deleteMany([]);
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(str_contains($exception->getMessage(), 'mongodb:'));
    }
}
