<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class CountDocumentsPayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed> $filter
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::CountDocuments;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            data: $this->filter,
            isObject: true,
        );
    }
}
