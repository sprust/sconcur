<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class FindOnePayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>           $filter
     * @param array<string, mixed>|null      $projection
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public ?array $projection = null,
        public array|string|null $hint = null,
        public ?array $collation = null,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::FindOne;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new FindOnePayloadParameters($this->filter, $this->projection, $this->hint, $this->collation),
            isObject: true,
        );
    }
}
