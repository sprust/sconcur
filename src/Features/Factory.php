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
    protected array $mongodb = [];

    public function sleep(): SleepFeature
    {
        return $this->sleep ??= new SleepFeature();
    }

    public function mongodb(ConnectionParameters $connection): MongoDBFeature
    {
        return $this->mongodb[$connection->toString()] ??= new MongodbFeature($connection);
    }
}
