<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class ListIndexesPayload extends BaseMongodbPayload
{
    public function __construct(
        public Connection $connection,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::ListIndexes;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new EmptyPayloadParameters(),
            isObject: true,
        );
    }
}
