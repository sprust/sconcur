<?php

declare(strict_types=1);

namespace SConcur\Features;

use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Sleep\SleepFeature;

class Features
{
    protected static ?SleepFeature $sleep = null;

    /**
     * @var array<string, MongoDBFeature>
     */
    protected static array $mongodbConnections = [];

    public static function sleep(): SleepFeature
    {
        return static::$sleep ??= new SleepFeature();
    }

    public static function mongodb(ConnectionParameters $connection): MongoDBFeature
    {
        $connectionKey = sprintf(
            '%s|%s|%s|%d',
            $connection->uri,
            $connection->database,
            $connection->collection,
            $connection->socketTimeoutMs,
        );

        return static::$mongodbConnections[$connectionKey] ??= new MongodbFeature($connection);
    }
}
