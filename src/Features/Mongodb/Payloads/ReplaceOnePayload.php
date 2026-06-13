<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class ReplaceOnePayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $replacement
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public array $replacement,
        public bool $upsert = false,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::ReplaceOne;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new ReplaceOnePayloadParameters($this->filter, $this->replacement, $this->upsert),
            isObject: true,
        );
    }
}
