<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

/**
 * Go: payloads.CreateIndexPayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class CreateIndexPayload extends BaseMongodbPayload
{
    /**
     * @param array<string, int|string> $keys
     */
    public function __construct(
        public Connection $connection,
        public array $keys,
        public ?string $name = null,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::CreateIndex;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new CreateIndexPayloadParameters(
                keys: $this->keys,
                name: $this->name,
            ),
            isObject: true,
        );
    }
}
