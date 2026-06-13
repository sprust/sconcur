<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use DateTime;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Features\Mongodb\Connection\Database;

class TestMongodbResolver
{
    protected static ?string $uri = null;

    protected static string $testDatabaseName = 'u-test';
    protected static string $benchmarkName    = 'benchmark';

    protected static string $objectId = '6919e3d1a3673d3f4d9137a3';

    public static function getDriverTestDatabase(): \MongoDB\Database
    {
        return new \MongoDB\Client(static::getUri())
            ->selectDatabase(static::$testDatabaseName);
    }

    public static function getDriverTestCollection(string $collectionName): \MongoDB\Collection
    {
        return self::getDriverTestDatabase()
            ->selectCollection($collectionName);
    }

    public static function getSconcurTestDatabase(): Database
    {
        return new Client(uri: static::getUri())
            ->selectDatabase(static::$testDatabaseName);
    }

    public static function getSconcurTestCollection(string $collectionName, ?int $timeoutMs = null): Collection
    {
        return new Client(uri: static::getUri(), timeoutMs: $timeoutMs)
            ->selectDatabase(static::$testDatabaseName)
            ->selectCollection($collectionName);
    }

    public static function getDriverBenchmarkCollection(): \MongoDB\Collection
    {
        return new \MongoDB\Client(static::getUri())
            ->selectDatabase(static::$testDatabaseName)
            ->selectCollection(static::$benchmarkName);
    }

    public static function getSconcurBenchmarkCollection(): Collection
    {
        return new Client(uri: static::getUri())
            ->selectDatabase(static::$testDatabaseName)
            ->selectCollection(static::$benchmarkName);
    }

    public static function getDriverObjectId(?string $id = null): ObjectId
    {
        return new ObjectId($id ?: static::$objectId);
    }

    public static function getSconcurObjectId(?string $id = null): ObjectId
    {
        return new ObjectId($id ?: static::$objectId);
    }

    public static function getDriverDateTime(?DateTime $dateTime = null): UTCDateTime
    {
        return new UTCDateTime($dateTime);
    }

    public static function getSconcurDateTime(?DateTime $dateTime = null): UTCDateTime
    {
        return $dateTime === null ? new UTCDateTime() : new UTCDateTime($dateTime);
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
