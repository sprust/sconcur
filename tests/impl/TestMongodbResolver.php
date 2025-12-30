<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use DateTime;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;

class TestMongodbResolver
{
    protected static ?string $uri = null;

    protected static string $testDatabaseName = 'u-test';
    protected static string $benchmarkName    = 'benchmark';

    protected static string $objectId = '6919e3d1a3673d3f4d9137a3';

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

    public static function getDriverObjectId(?string $id = null): \MongoDB\BSON\ObjectId
    {
        return new \MongoDB\BSON\ObjectId($id ?: static::$objectId);
    }

    public static function getSconcurObjectId(?string $id = null): ObjectId
    {
        return new ObjectId($id ?: static::$objectId);
    }

    public static function getDriverDateTime(?DateTime $dateTime = null): \MongoDB\BSON\UTCDateTime
    {
        return new \MongoDB\BSON\UTCDateTime($dateTime);
    }

    public static function getSconcurDateTime(?DateTime $dateTime = null): UTCDateTime
    {
        return new UTCDateTime($dateTime);
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
