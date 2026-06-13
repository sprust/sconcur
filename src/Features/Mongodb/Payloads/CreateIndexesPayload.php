<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;
use SConcur\Features\Mongodb\Payloads\Support\IndexName;

readonly class CreateIndexesPayload extends BaseMongodbPayload
{
    /**
     * @param array<int, array{keys: array<string, int|string>, name?: string}> $indexes
     */
    public function __construct(
        public Connection $connection,
        public array $indexes,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::CreateIndexes;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            data: [
                'ix' => $this->prepareIndexes(),
            ],
            isObject: true,
        );
    }

    /**
     * @return array<int, array{k: array<string, int|string>, n: string}>
     */
    private function prepareIndexes(): array
    {
        $preparedIndexes = [];

        foreach ($this->indexes as $index) {
            $keys = $index['keys'];

            $preparedIndexes[] = [
                'k' => $keys,
                'n' => $index['name'] ?? IndexName::fromKeys($keys),
            ];
        }

        return $preparedIndexes;
    }
}
