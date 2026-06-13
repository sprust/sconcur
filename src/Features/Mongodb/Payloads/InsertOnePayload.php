<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class InsertOnePayload extends BaseMongodbPayload
{
    /**
     * @param array<int|string|float|bool|null, mixed> $document
     */
    public function __construct(
        public Connection $connection,
        public array $document,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::InsertOne;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new InsertOnePayloadParameters($this->document),
            isObject: true,
        );
    }
}
