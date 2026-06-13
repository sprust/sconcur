<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

/**
 * Go: payloads.DropIndexPayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class DropIndexPayload extends BaseMongodbPayload
{
    /**
     * @param array<string, int|string>|string $index
     */
    public function __construct(
        public Connection $connection,
        public array|string $index,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::DropIndex;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new DropIndexPayloadParameters($this->index),
            isObject: true,
        );
    }
}
