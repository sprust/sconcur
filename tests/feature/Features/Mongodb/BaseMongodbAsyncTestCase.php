<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb;

use MongoDB\Client;
use MongoDB\Collection;
use SConcur\Features\Features;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;
use Throwable;

abstract class BaseMongodbAsyncTestCase extends BaseAsyncTestCase
{
    protected MongodbFeature $feature;
    protected Collection $driverCollection;

    abstract protected function getCollectionName(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionParameters = new ConnectionParameters(
            uri: TestMongodbUriResolver::get(),
            database: 'u-test',
            collection: $this->getCollectionName(),
        );

        $this->driverCollection = new Client($connectionParameters->uri)
            ->selectDatabase($connectionParameters->database)
            ->selectCollection($connectionParameters->collection);

        $this->feature = Features::mongodb($connectionParameters);

        $this->driverCollection->deleteMany([]);
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(str_contains($exception->getMessage(), 'mongodb:'));
    }
}
