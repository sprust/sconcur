<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

/**
 * Go: payloads.RenameCollectionPayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class RenameCollectionPayload extends BaseMongodbPayload
{
    public function __construct(
        public Connection $connection,
        public string $target,
        public bool $dropTarget = false,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::RenameCollection;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new RenameCollectionPayloadParameters($this->target, $this->dropTarget),
            isObject: true,
        );
    }
}
