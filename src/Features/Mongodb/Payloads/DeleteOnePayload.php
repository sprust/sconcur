<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

/**
 * Go: payloads.DeleteOnePayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class DeleteOnePayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>           $filter
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public array|string|null $hint = null,
        public ?array $collation = null,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::DeleteOne;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new DeleteOnePayloadParameters(
                filter: $this->filter,
                hint: $this->hint,
                collation: $this->collation,
            ),
            isObject: true,
        );
    }
}
