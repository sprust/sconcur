<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

/**
 * Go: payloads.AggregatePayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class AggregatePayload extends BaseMongodbPayload
{
    /**
     * @param array<int, array<string, mixed>> $pipeline
     */
    public function __construct(
        public Connection $connection,
        public array $pipeline,
        public int $batchSize,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::Aggregate;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new AggregatePayloadParameters(
                pipeline: $this->pipeline,
                batchSize: $this->batchSize,
            ),
            isObject: true,
        );
    }
}
