<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

/**
 * Go: payloads.FindOneAndReplacePayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class FindOneAndReplacePayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>      $replacement
     * @param array<string, mixed>|null $projection
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public array $replacement,
        public ?array $projection = null,
        public bool $upsert = false,
        public bool $returnDocument = true,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::FindOneAndReplace;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new FindOneAndReplacePayloadParameters($this->filter, $this->replacement, $this->projection, $this->upsert, $this->returnDocument),
            isObject: true,
        );
    }
}
