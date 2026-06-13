<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Exceptions\InvalidMongodbBulkWriteOperationException;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class BulkWritePayload extends BaseMongodbPayload
{
    /**
     * @param array<int, mixed> $operations
     */
    public function __construct(
        public Connection $connection,
        public array $operations,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::BulkWrite;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            data: $this->prepareOperations(),
            isObject: true,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prepareOperations(): array
    {
        $preparedOperations = [];

        foreach ($this->operations as $operation) {
            $type  = array_key_first($operation);
            $value = $operation[$type];

            // TODO: value validation
            $preparedOperations[] = [
                'type'  => $type,
                'model' => match ($type) {
                    'insertOne' => [
                        'document' => $value[0] ?: [],
                    ],
                    'updateOne', 'updateMany' => [
                        'filter' => $value[0] ?: [],
                        'update' => $value[1] ?: [],
                        'upsert' => $value[2]['upsert'] ?? false, // TODO
                    ],
                    'deleteOne', 'deleteMany' => [
                        'filter' => $value[0] ?: [],
                    ],
                    'replaceOne' => [
                        'filter'      => $value[0] ?: [],
                        'replacement' => $value[1] ?: [],
                        'upsert'      => $value[2]['upsert'] ?? false, // TODO
                    ],
                    default => throw new InvalidMongodbBulkWriteOperationException(
                        operationType: (string) $type
                    )
                },
            ];
        }

        return $preparedOperations;
    }
}
