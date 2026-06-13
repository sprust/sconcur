<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class FindOneAndDeletePayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>|null $projection
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public ?array $projection = null,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::FindOneAndDelete;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            payload: new FindOneAndDeletePayloadParameters($this->filter, $this->projection),
            isObject: true,
        );
    }
}
