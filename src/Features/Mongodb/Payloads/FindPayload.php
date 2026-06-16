<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

/**
 * Go: payloads.FindPayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class FindPayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>           $filter
     * @param array<string, mixed>|null      $projection
     * @param array<string, int>|null        $sort
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public ?array $projection = null,
        public ?array $sort = null,
        public int $limit = 0,
        public int $skip = 0,
        public int $batchSize = 50,
        public array|string|null $hint = null,
        public ?array $collation = null,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::Find;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new FindPayloadParameters(
                filter: $this->filter,
                projection: $this->projection,
                sort: $this->sort,
                limit: $this->limit,
                skip: $this->skip,
                batchSize: $this->batchSize,
                hint: $this->hint,
                collation: $this->collation,
            ),
            isObject: true,
        );
    }
}
