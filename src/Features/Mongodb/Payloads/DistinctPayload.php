<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class DistinctPayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>|null $collation
     */
    public function __construct(
        public Connection $connection,
        public string $fieldName,
        public array $filter = [],
        public ?array $collation = null,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::Distinct;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            data: [
                'fn' => $this->fieldName,
                'f'  => $this->filter,
            ] + $this->encodeOptions(
                collation: $this->collation,
            ),
            isObject: true,
        );
    }
}
