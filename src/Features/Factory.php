<?php

declare(strict_types=1);

namespace SConcur\Features;

use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Sleep\SleepFeature;

class Factory
{
    protected ?SleepFeature $sleep = null;

    /**
     * @var array<string, MongoDBFeature>
     */
    protected array $mongodbConnections = [];

    public function sleep(): SleepFeature
    {
        return $this->sleep ??= new SleepFeature();
    }

    public function mongodb(ConnectionParameters $connection): MongoDBFeature
    {
        $connectionKey = "$connection->uri|$connection->database|$connection->collection";

        return $this->mongodbConnections[$connectionKey] ??= new MongodbFeature($connection);
    }
}
