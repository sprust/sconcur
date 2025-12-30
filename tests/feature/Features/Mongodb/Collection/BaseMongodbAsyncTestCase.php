<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use Throwable;

abstract class BaseMongodbAsyncTestCase extends BaseAsyncTestCase
{
    protected \MongoDB\Collection $driverCollection;
    protected Collection $sconcurCollection;

    abstract protected function getCollectionName(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $collectionName = 'async_' . ucfirst($this->getCollectionName());

        $this->driverCollection  = TestMongodbResolver::getDriverTestCollection($collectionName);
        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $this->driverCollection->deleteMany([]);
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(str_contains($exception->getMessage(), 'mongodb:'));
    }
}
