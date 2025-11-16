<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb;

use Iterator;
use MongoDB\Collection;
use MongoDB\Driver\CursorInterface as DriverCursorInterface;
use RuntimeException;
use SConcur\Entities\Context;
use SConcur\Exceptions\InvalidMongodbBulkWriteOperationException;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Results\AggregateResult;
use SConcur\Features\Mongodb\Results\BulkWriteResult;
use SConcur\Features\Mongodb\Results\InsertOneResult;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\SConcur;

readonly class MongodbFeature
{
    private function __construct()
    {
    }

    public static function insertOne(
        Context $context,
        ConnectionParameters $connection,
        array $document,
    ): InsertOneResult {
        $serialized = DocumentSerializer::serialize($document);

        $taskResult = SConcur::getCurrentFlow()->pushTask(
            context: $context,
            method: MethodEnum::Mongodb,
            payload: static::serialize(
                connection: $connection,
                command: CommandEnum::InsertOne,
                data: $serialized,
            )
        );

        if ($taskResult->isError) {
            throw new RuntimeException(
                $taskResult->payload ?: 'Unknown error',
            );
        }

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new InsertOneResult(
            insertedId: $docResult['insertedid'],
        );
    }

    /**
     * @throws InvalidMongodbBulkWriteOperationException
     */
    public static function bulkWrite(
        Context $context,
        ConnectionParameters $connection,
        array $operations,
    ): BulkWriteResult {
        $serialized = DocumentSerializer::serialize(
            static::prepareOperations($operations)
        );

        $taskResult = SConcur::getCurrentFlow()->pushTask(
            context: $context,
            method: MethodEnum::Mongodb,
            payload: static::serialize(
                connection: $connection,
                command: CommandEnum::BulkWrite,
                data: $serialized,
            )
        );

        if ($taskResult->isError) {
            throw new RuntimeException(
                $taskResult->payload ?: 'Unknown error',
            );
        }

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new BulkWriteResult(
            insertedCount: (int) $docResult['insertedcount'],
            matchedCount: (int) $docResult['matchedcount'],
            modifiedCount: (int) $docResult['modifiedcount'],
            deletedCount: (int) $docResult['deletedcount'],
            upsertedCount: (int) $docResult['upsertedcount'],
            upsertedIds: (array) $docResult['upsertedids'],
        );
    }

    /**
     * @throws InvalidMongodbBulkWriteOperationException
     */
    public static function aggregate(
        Context $context,
        Collection $collection,
        ConnectionParameters $connection,
        array $pipeline,
    ): DriverCursorInterface|Iterator {
        if (!SConcur::isConcurrency()) {
            return $collection->aggregate($pipeline);
        }

        $connector = SConcur::getServerConnector()->clone($context);

        $serialized = DocumentSerializer::serialize(
            static::prepareOperations($pipeline)
        );

        $runningTask = $connector->write(
            context: $context,
            method: MethodEnum::Mongodb,
            payload: static::serialize(
                connection: $connection,
                command: CommandEnum::Aggregate,
                data: $serialized,
            )
        );

        $connector->disconnect();

        return new AggregateResult(
            context: $context,
            runningTask: $runningTask,
        );
    }

    protected static function serialize(
        ConnectionParameters $connection,
        CommandEnum $command,
        string $data
    ): string {
        return json_encode([
            'ul' => $connection->uri,
            'db' => $connection->database,
            'cl' => $connection->collection,
            'cm' => $command->value,
            'dt' => $data,
        ]);
    }

    /**
     * @param array<int, mixed> $operations
     *
     * @return array<int, array{type: string, model: array<string, mixed>}>
     *
     * @throws InvalidMongodbBulkWriteOperationException
     */
    protected static function prepareOperations(array $operations): array
    {
        $result = [];

        foreach ($operations as $operation) {
            $type  = array_key_first($operation);
            $value = $operation[$type];

            // TODO: value validation
            $result[] = [
                'type'  => $type,
                'model' => match ($type) {
                    'insertOne' => [
                        'document' => $value[0],
                    ],
                    'updateOne', 'updateMany' => [
                        'filter' => $value[0],
                        'update' => $value[1],
                        'upsert' => $value[2]['upsert'] ?? false, // TODO
                    ],
                    'deleteOne', 'deleteMany' => [
                        'filter' => $value[0],
                    ],
                    'replaceOne' => [
                        'filter'      => $value[0],
                        'replacement' => $value[1],
                        'upsert'      => $value[2]['upsert'] ?? false, // TODO
                    ],
                    default => throw new InvalidMongodbBulkWriteOperationException(
                        operationType: (string) $type
                    )
                },
            ];
        }

        return $result;
    }
}
