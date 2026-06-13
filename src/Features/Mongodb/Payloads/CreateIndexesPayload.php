<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class CreateIndexesPayload extends BaseMongodbPayload
{
    /**
     * @param array<int, array{keys: array<string, int|string>, name?: string}> $indexes
     */
    public function __construct(
        public Connection $connection,
        public array $indexes,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::CreateIndexes;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new CreateIndexesPayloadParameters($this->indexes),
            isObject: true,
        );
    }
}
