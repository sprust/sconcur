<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class UpdateOnePayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>                  $filter
     * @param array<string, mixed>                  $update
     * @param array<int, array<string, mixed>>|null $arrayFilters
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public array $update,
        public bool $upsert = false,
        public ?array $arrayFilters = null,
        public array|string|null $hint = null,
        public ?array $collation = null,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::UpdateOne;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new UpdateOnePayloadParameters(
                filter: $this->filter,
                update: $this->update,
                upsert: $this->upsert,
                arrayFilters: $this->arrayFilters,
                hint: $this->hint,
                collation: $this->collation
            ),
            isObject: true,
        );
    }
}
