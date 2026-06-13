<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

/**
 * Go: payloads.FindOneAndUpdatePayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class FindOneAndUpdatePayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>                  $filter
     * @param array<string, mixed>                  $update
     * @param array<string, mixed>|null             $projection
     * @param array<int, array<string, mixed>>|null $arrayFilters
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public array $update,
        public ?array $projection = null,
        public bool $upsert = false,
        public bool $returnDocument = true,
        public ?array $arrayFilters = null,
        public array|string|null $hint = null,
        public ?array $collation = null,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::FindOneAndUpdate;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new FindOneAndUpdatePayloadParameters($this->filter, $this->update, $this->projection, $this->upsert, $this->returnDocument, $this->arrayFilters, $this->hint, $this->collation),
            isObject: true,
        );
    }
}
