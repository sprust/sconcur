<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use DateTime;
use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Client as DriverClient;
use MongoDB\Collection as DriverCollection;
use MongoDB\Database as DriverDatabase;
use MongoDB\BSON\UTCDateTime;
use SConcur\Bson\ObjectId as SconcurObjectId;
use SConcur\Bson\UTCDateTime as SconcurUTCDateTime;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Features\Mongodb\Connection\Database;

class TestMongodbResolver
{
    protected static ?string $uri = null;

    protected static string $testDatabaseName = 'u-test';
    protected static string $benchmarkName    = 'benchmark';

    protected static string $objectId = '6919e3d1a3673d3f4d9137a3';

    public static function getDriverTestDatabase(): DriverDatabase
    {
        return new DriverClient(static::getUri())
            ->selectDatabase(static::$testDatabaseName);
    }

    public static function getDriverTestCollection(string $collectionName): DriverCollection
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
        return new Client(
            uri: static::getUri(),
            timeoutMs: $timeoutMs,
        )
            ->selectDatabase(static::$testDatabaseName)
            ->selectCollection($collectionName);
    }

    public static function getDriverBenchmarkCollection(): DriverCollection
    {
        return new DriverClient(static::getUri())
            ->selectDatabase(static::$testDatabaseName)
            ->selectCollection(static::$benchmarkName);
    }

    public static function getSconcurBenchmarkCollection(): Collection
    {
        return new Client(uri: static::getUri())
            ->selectDatabase(static::$testDatabaseName)
            ->selectCollection(static::$benchmarkName);
    }

    /**
     * Drops and reseeds the benchmark collection with $documents documents
     * (batched insertMany via the native driver, outside any measurement).
     * `_id` is an explicit integer 1..$documents, so point reads/updates/deletes
     * by key are deterministic; `IIID`/`bool`/`date` match the filters the
     * benchmark scripts use, so scans/counts work over the whole dataset.
     */
    public static function seedBenchmarkCollection(int $documents): void
    {
        $collection = static::getDriverBenchmarkCollection();

        $collection->drop();

        if ($documents <= 0) {
            return;
        }

        $objectId  = static::getDriverObjectId();
        $dateTime  = static::getDriverDateTime();
        $chunkSize = 10000;

        for ($chunkStart = 1; $chunkStart <= $documents; $chunkStart += $chunkSize) {
            $chunkEnd = min($chunkStart + $chunkSize - 1, $documents);
            $chunk    = [];

            for ($documentId = $chunkStart; $documentId <= $chunkEnd; ++$documentId) {
                $chunk[] = [
                    '_id'    => $documentId,
                    'IIID'   => $objectId,
                    'bool'   => true,
                    'uniq'   => "seed-$documentId",
                    'date'   => $dateTime,
                    'amount' => $documentId,
                ];
            }

            $collection->insertMany($chunk, ['ordered' => false]);
        }
    }

    public static function getDriverObjectId(?string $id = null): ObjectId
    {
        return new ObjectId($id ?: static::$objectId);
    }

    public static function getSconcurObjectId(?string $id = null): SconcurObjectId
    {
        return new SconcurObjectId($id ?: static::$objectId);
    }

    public static function getDriverDateTime(?DateTimeInterface $dateTime = null): UTCDateTime
    {
        return new UTCDateTime($dateTime);
    }

    public static function getSconcurDateTime(?DateTimeInterface $dateTime = null): SconcurUTCDateTime
    {
        $dateTime ??= new DateTime();

        // Whole milliseconds, computed without going through a float, so a
        // sub-millisecond fraction cannot round the value up into the next one.
        $epochMs = $dateTime->getTimestamp() * 1000 + intdiv((int) $dateTime->format('u'), 1000);

        return new SconcurUTCDateTime($epochMs);
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
