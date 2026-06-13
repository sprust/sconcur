<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class ListCollectionsPayload extends BaseMongodbPayload
{
    public function __construct(
        public Connection $connection,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::ListCollections;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            data: [],
            isObject: true,
        );
    }
}
