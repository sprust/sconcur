<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

use Iterator;
use RuntimeException;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\InvalidMongodbBulkWriteOperationException;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Results\BulkWriteResult;
use SConcur\Features\Mongodb\Results\InsertManyResult;
use SConcur\Features\Mongodb\Results\InsertOneResult;
use SConcur\Features\Mongodb\Results\IteratorResult;
use SConcur\Features\Mongodb\Results\UpdateResult;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\State;

readonly class Collection
{
    protected const string RESULT_KEY = '_r';

    protected ConnectionParameters $connection;
    protected MethodEnum $method;

    public function __construct(public Database $database, public string $name)
    {
        $this->connection = new ConnectionParameters(
            uri: $this->database->client->uri,
            database: $this->database->name,
            collection: $this->name,
            socketTimeoutMs: $this->database->client->socketTimeoutMs,
        );

        $this->method = MethodEnum::MongodbCollection;
    }

    /**
     * @param array<int|string|float|bool|null, mixed> $document
     */
    public function insertOne(Context $context, array $document): InsertOneResult
    {
        $serialized = DocumentSerializer::serialize($document);

        $taskResult = $this->exec(
            context: $context,
            command: CommandEnum::InsertOne,
            payload: $serialized,
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new InsertOneResult(
            insertedId: $docResult['insertedid'],
        );
    }

    /**
     * @param array<int, array<int|string|float|bool|null, mixed>> $documents
     */
    public function insertMany(Context $context, array $documents): InsertManyResult
    {
        $serialized = DocumentSerializer::serialize($documents);

        $taskResult = $this->exec(
            context: $context,
            command: CommandEnum::InsertMany,
            payload: $serialized,
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new InsertManyResult(
            insertedIds: $docResult['insertedids'],
        );
    }

    /**
     * @param array<int, mixed> $operations
     */
    public function bulkWrite(Context $context, array $operations): BulkWriteResult
    {
        $preparedOperations = [];

        foreach ($operations as $operation) {
            $type  = array_key_first($operation);
            $value = $operation[$type];

            // TODO: value validation
            $preparedOperations[] = [
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

        $serialized = DocumentSerializer::serialize($preparedOperations);

        $taskResult = $this->exec(
            context: $context,
            command: CommandEnum::BulkWrite,
            payload: $serialized,
        );

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
     * @param array<string, mixed> $filter
     */
    public function countDocuments(Context $context, array $filter): int
    {
        $serialized = DocumentSerializer::serialize($filter);

        $taskResult = $this->exec(
            context: $context,
            command: CommandEnum::CountDocuments,
            payload: $serialized,
        );

        $result = $taskResult->payload;

        if (ctype_digit($result) === false) {
            throw new RuntimeException(
                "Invalid countDocuments result: $result"
            );
        }

        return (int) $result;
    }

    /**
     * @param array<int, array<string, mixed>> $pipeline
     *
     * @return Iterator<int, array<int|string|float|bool|null, mixed>>
     */
    public function aggregate(Context $context, array $pipeline): Iterator
    {
        $serialized = DocumentSerializer::serialize($pipeline);

        return new IteratorResult(
            context: $context,
            payload: $this->serializePayload(
                connection: $this->connection,
                command: CommandEnum::Aggregate,
                data: $serialized,
            ),
            resultKey: static::RESULT_KEY,
        );
    }

    /**
     * @param array<int, mixed>    $filter
     * @param array<string, mixed> $update
     * @param array{
     *     upsert?: bool,
     * } $options
     */
    public function updateOne(Context $context, array $filter, array $update, array $options = []): UpdateResult
    {
        $serialized = DocumentSerializer::serialize([
            'f'  => DocumentSerializer::serialize($filter),
            'u'  => DocumentSerializer::serialize($update),
            'ou' => $options['upsert'] ?? false,
        ]);

        $taskResult = $this->exec(
            context: $context,
            command: CommandEnum::UpdateOne,
            payload: $serialized,
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new UpdateResult(
            matchedCount: (int) $docResult['matchedcount'],
            modifiedCount: (int) $docResult['modifiedcount'],
            upsertedCount: (int) $docResult['upsertedcount'],
            upsertedId: $docResult['upsertedid'],
        );
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<int|string, mixed>|null
     */
    public function findOne(Context $context, array $filter): ?array
    {
        $serialized = DocumentSerializer::serialize([
            'f' => DocumentSerializer::serialize($filter),
        ]);

        $taskResult = $this->exec(
            context: $context,
            command: CommandEnum::FindOne,
            payload: $serialized,
        );

        if (!$taskResult->payload) {
            return null;
        }

        return DocumentSerializer::unserialize($taskResult->payload) ?: null;
    }

    /**
     * @param array<string, int|string> $keys
     */
    public function createIndex(Context $context, array $keys, ?string $name = null): string
    {
        if ($name) {
            $indexName = $name;
        } else {
            $indexNames = [];

            foreach ($keys as $field => $type) {
                $indexNames[] = "{$field}_$type";
            }

            $indexName = implode('_', $indexNames);
        }

        $serialized = DocumentSerializer::serialize([
            'k' => DocumentSerializer::serialize($keys),
            'n' => $indexName,
        ]);

        $taskResult = $this->exec(
            context: $context,
            command: CommandEnum::CreateIndex,
            payload: $serialized,
        );

        return $taskResult->payload;
    }

    protected function exec(
        Context $context,
        CommandEnum $command,
        string $payload
    ): TaskResultDto {
        return State::getCurrentFlow()->exec(
            context: $context,
            method: $this->method,
            payload: $this->serializePayload(
                connection: $this->connection,
                command: $command,
                data: $payload,
            )
        );
    }

    protected function serializePayload(
        ConnectionParameters $connection,
        CommandEnum $command,
        string $data
    ): string {
        return json_encode([
            'ul'  => $connection->uri,
            'db'  => $connection->database,
            'cl'  => $connection->collection,
            'sto' => $connection->socketTimeoutMs,
            'cm'  => $command->value,
            'dt'  => $data,
        ]);
    }
}
