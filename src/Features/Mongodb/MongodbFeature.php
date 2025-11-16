<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb;

use Iterator;
use MongoDB\BulkWriteResult as DriverBulkWriteResult;
use MongoDB\Collection;
use MongoDB\Driver\CursorInterface as DriverCursorInterface;
use MongoDB\InsertOneResult as DriverInsertOneResult;
use RuntimeException;
use SConcur\Entities\Context;
use SConcur\Exceptions\ContinueException;
use SConcur\Exceptions\FeatureResultNotFoundException;
use SConcur\Exceptions\InvalidMongodbBulkWriteOperationException;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Results\AggregateResult;
use SConcur\Features\Mongodb\Results\BulkWriteResult as PackageBulkWriteResult;
use SConcur\Features\Mongodb\Results\InsertOneResult as PackageInsertOneResult;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\SConcur;

readonly class MongodbFeature
{
    private function __construct()
    {
    }

    /**
     * @throws FeatureResultNotFoundException
     * @throws ContinueException
     */
    public static function insertOne(
        Context $context,
        Collection $collection,
        ConnectionParameters $connection,
        array $document,
    ): DriverInsertOneResult|PackageInsertOneResult {
        if (!SConcur::isConcurrency()) {
            return $collection->insertOne($document);
        }

        $connector = SConcur::getServerConnector()->clone($context);

        $serialized = DocumentSerializer::serialize($document);

        $runningTask = $connector->write(
            context: $context,
            method: MethodEnum::Mongodb,
            payload: static::serialize(
                connection: $connection,
                command: CommandEnum::InsertOne,
                data: $serialized,
            )
        );

        $connector->disconnect();

        SConcur::wait($runningTask->key);

        $result = SConcur::detectResult(taskKey: $runningTask->key);

        if ($result->isError) {
            throw new RuntimeException(
                $result->payload ?: 'Unknown error',
            );
        }

        $docResult = (array) DocumentSerializer::unserialize($result->payload)->toPHP();

        return new PackageInsertOneResult(
            insertedId: $docResult['insertedid'],
        );
    }

    /**
     * @throws InvalidMongodbBulkWriteOperationException
     * @throws FeatureResultNotFoundException
     * @throws ContinueException
     */
    public static function bulkWrite(
        Context $context,
        Collection $collection,
        ConnectionParameters $connection,
        array $operations,
    ): DriverBulkWriteResult|PackageBulkWriteResult {
        if (!SConcur::isConcurrency()) {
            return $collection->bulkWrite($operations);
        }

        $connector = SConcur::getServerConnector()->clone($context);

        $serialized = DocumentSerializer::serialize(
            static::prepareOperations($operations)
        );

        $runningTask = $connector->write(
            context: $context,
            method: MethodEnum::Mongodb,
            payload: static::serialize(
                connection: $connection,
                command: CommandEnum::BulkWrite,
                data: $serialized,
            )
        );

        $connector->disconnect();

        SConcur::wait($runningTask->key);

        $result = SConcur::detectResult(taskKey: $runningTask->key);

        if ($result->isError) {
            throw new RuntimeException(
                $result->payload ?: 'Unknown error',
            );
        }

        $docResult = (array) DocumentSerializer::unserialize($result->payload)->toPHP();

        return new PackageBulkWriteResult(
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
