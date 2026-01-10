<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

use Iterator;
use RuntimeException;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\InvalidMongodbBulkWriteOperationException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Results\BulkWriteResult;
use SConcur\Features\Mongodb\Results\DeleteResult;
use SConcur\Features\Mongodb\Results\InsertManyResult;
use SConcur\Features\Mongodb\Results\InsertOneResult;
use SConcur\Features\Mongodb\Results\IteratorResult;
use SConcur\Features\Mongodb\Results\UpdateResult;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;

readonly class Collection
{
    protected const string RESULT_KEY = '_r';

    protected MethodEnum $method;

    protected string $uri;
    protected string $databaseName;
    protected string $collectionName;
    protected int $socketTimeoutMs;

    public function __construct(public Database $database, public string $name)
    {
        $this->uri             = $this->database->client->uri;
        $this->databaseName    = $this->database->name;
        $this->collectionName  = $this->name;
        $this->socketTimeoutMs = $this->database->client->socketTimeoutMs;

        $this->method = MethodEnum::MongodbCollection;
    }

    /**
     * @param array<int|string|float|bool|null, mixed> $document
     */
    public function insertOne(array $document): InsertOneResult
    {
        $serialized = DocumentSerializer::serialize($document);

        $taskResult = $this->exec(
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
    public function insertMany(array $documents): InsertManyResult
    {
        $serialized = DocumentSerializer::serialize($documents);

        $taskResult = $this->exec(
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
    public function bulkWrite(array $operations): BulkWriteResult
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
    public function countDocuments(array $filter): int
    {
        $serialized = DocumentSerializer::serialize($filter);

        $taskResult = $this->exec(
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
    public function aggregate(array $pipeline, int $batchSize = 30): Iterator
    {
        $serialized = DocumentSerializer::serialize([
            'p'  => DocumentSerializer::serialize($pipeline),
            'bs' => $batchSize,
        ]);

        return new IteratorResult(
            method: $this->method,
            payload: $this->serializePayload(
                command: CommandEnum::Aggregate,
                data: $serialized,
            ),
            nextMethod: MethodEnum::MongodbStateful,
            nextPayload: json_encode([
                'cm' => CommandEnum::Aggregate,
            ]),
            resultKey: static::RESULT_KEY,
        );
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    public function updateOne(array $filter, array $update, bool $upsert = false): UpdateResult
    {
        return $this->update(
            isMany: false,
            filter: $filter,
            update: $update,
            upsert: $upsert,
        );
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    public function updateMany(array $filter, array $update, bool $upsert = false): UpdateResult
    {
        return $this->update(
            isMany: true,
            filter: $filter,
            update: $update,
            upsert: $upsert,
        );
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $projection
     *
     * @return array<int|string, mixed>|null
     */
    public function findOne(array $filter, ?array $projection = null): ?array
    {
        $serialized = DocumentSerializer::serialize([
            'f'  => DocumentSerializer::serialize($filter),
            'op' => ($projection === null) ? "" : DocumentSerializer::serialize($projection),
        ]);

        $taskResult = $this->exec(
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
    public function createIndex(array $keys, ?string $name = null): string
    {
        if ($name) {
            $indexName = $name;
        } else {
            $indexName = $this->makeIndexNameByKeys($keys);
        }

        $serialized = DocumentSerializer::serialize([
            'k' => DocumentSerializer::serialize($keys),
            'n' => $indexName,
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::CreateIndex,
            payload: $serialized,
        );

        return $taskResult->payload;
    }

    /**
     * @param array<string, int|string>|string $index
     */
    public function dropIndex(array|string $index): string
    {
        if (is_string($index)) {
            $indexName = $index;
        } else {
            $indexName = $this->makeIndexNameByKeys($index);
        }

        $serialized = DocumentSerializer::serialize([
            'n' => $indexName,
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::DropIndex,
            payload: $serialized,
        );

        return $taskResult->payload;
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function deleteOne(array $filter): DeleteResult
    {
        $serialized = DocumentSerializer::serialize([
            'f' => DocumentSerializer::serialize($filter),
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::DeleteOne,
            payload: $serialized,
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new DeleteResult(
            deletedCount: (int) $docResult['n'],
        );
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function deleteMany(array $filter): DeleteResult
    {
        $serialized = DocumentSerializer::serialize([
            'f' => DocumentSerializer::serialize($filter),
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::DeleteMany,
            payload: $serialized,
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new DeleteResult(
            deletedCount: (int) $docResult['n'],
        );
    }

    public function drop(): void
    {
        $this->exec(
            command: CommandEnum::Drop,
            payload: '{}',
        );
    }

    /**
     * @param array<string, int|string> $keys
     */
    public function makeIndexNameByKeys(array $keys): string
    {
        $indexNames = [];

        foreach ($keys as $field => $type) {
            $indexNames[] = "{$field}_$type";
        }

        return implode('_', $indexNames);
    }

    protected function exec(CommandEnum $command, string $payload): TaskResultDto
    {
        return FeatureExecutor::exec(
            method: $this->method,
            payload: $this->serializePayload(
                command: $command,
                data: $payload,
            )
        );
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    protected function update(
        bool $isMany,
        array $filter,
        array $update,
        bool $upsert = false
    ): UpdateResult {
        $serialized = DocumentSerializer::serialize([
            'f'  => DocumentSerializer::serialize($filter),
            'u'  => DocumentSerializer::serialize($update),
            'ou' => $upsert,
        ]);

        $taskResult = $this->exec(
            command: $isMany ? CommandEnum::UpdateMany : CommandEnum::UpdateOne,
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

    protected function serializePayload(CommandEnum $command, string $data): string
    {
        return json_encode([
            'ul'  => $this->uri,
            'db'  => $this->databaseName,
            'cl'  => $this->collectionName,
            'sto' => $this->socketTimeoutMs,
            'cm'  => $command->value,
            'dt'  => $data,
        ]);
    }
}
