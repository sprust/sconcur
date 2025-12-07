<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb;

use Iterator;
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
    protected MethodEnum $method;

    public function __construct(
        protected ConnectionParameters $connection,
    ) {
        $this->method = MethodEnum::Mongodb;
    }

    /**
     * @param array<int|string|float|bool|null, mixed> $document
     */
    public function insertOne(Context $context, array $document): InsertOneResult
    {
        $serialized = DocumentSerializer::serialize($document);

        $taskResult = SConcur::getCurrentFlow()->exec(
            context: $context,
            method: $this->method,
            payload: static::serializePayload(
                connection: $this->connection,
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
     * @param array<int, mixed> $operations
     */
    public function bulkWrite(Context $context, array $operations): BulkWriteResult
    {
        $serialized = DocumentSerializer::serialize(
            static::prepareOperations($operations)
        );

        $taskResult = SConcur::getCurrentFlow()->exec(
            context: $context,
            method: $this->method,
            payload: static::serializePayload(
                connection: $this->connection,
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
     * @param array<int, array<string, mixed>> $pipeline
     */
    public function aggregate(Context $context, array $pipeline): Iterator
    {
        $serialized = DocumentSerializer::serialize($pipeline);

        return new AggregateResult(
            context: $context,
            payload: static::serializePayload(
                connection: $this->connection,
                command: CommandEnum::Aggregate,
                data: $serialized,
            ),
        );
    }

    protected static function serializePayload(
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
