<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Connection\Collection;

class TestMongodbResolver
{
    protected static ?string $uri = null;

    protected static string $testDatabaseName = 'u-test';
    protected static string $benchmarkName    = 'benchmark';

    public static function getDriverTestCollection(string $collectionName): \MongoDB\Collection
    {
        return new \MongoDB\Client(static::getUri())
            ->selectDatabase(static::$testDatabaseName)
            ->selectCollection($collectionName);
    }

    public static function getSconcurTestCollection(string $collectionName, ?int $socketTimeoutMs = null): Collection
    {
        return new Client(uri: static::getUri(), socketTimeoutMs: $socketTimeoutMs)
            ->selectDatabase(static::$testDatabaseName)
            ->selectCollection($collectionName);
    }

    public static function getDriverBenchmarkCollection(): \MongoDB\Collection
    {
        return new \MongoDB\Client(static::getUri())
            ->selectDatabase(static::$benchmarkName)
            ->selectCollection(static::$benchmarkName);
    }

    public static function getSconcurBenchmarkCollection(): Collection
    {
        return new Client(uri: static::getUri())
            ->selectDatabase(static::$benchmarkName)
            ->selectCollection(static::$benchmarkName);
    }

    protected static function getUri(): string
    {
        if (static::$uri !== null) {
            return static::$uri;
        }

        $host = $_ENV['MONGO_HOST'];
        $user = $_ENV['MONGO_ADMIN_USERNAME'];
        $pass = $_ENV['MONGO_ADMIN_PASSWORD'];
        $port = $_ENV['MONGO_PORT'];

        return static::$uri = "mongodb://$user:$pass@$host:$port";
    }
}
