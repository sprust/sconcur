<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;
use Throwable;

abstract class BaseMongodbAsyncTestCase extends BaseAsyncTestCase
{
    protected \MongoDB\Collection $driverCollection;
    protected Collection $sconcurCollection;

    abstract protected function getCollectionName(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $uri        = TestMongodbUriResolver::get();
        $database   = 'u-test';
        $collection = 'async_' . ucfirst($this->getCollectionName());

        $this->driverCollection = new \MongoDB\Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);

        $this->sconcurCollection = new Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);

        $this->driverCollection->deleteMany([]);
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(str_contains($exception->getMessage(), 'mongodb:'));
    }
}
