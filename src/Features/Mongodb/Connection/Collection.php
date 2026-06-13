<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

use Iterator;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\Mongodb\InvalidCountResultException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Mongodb\Payloads\AggregatePayload;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\BulkWritePayload;
use SConcur\Features\Mongodb\Payloads\CountDocumentsPayload;
use SConcur\Features\Mongodb\Payloads\CreateIndexesPayload;
use SConcur\Features\Mongodb\Payloads\CreateIndexPayload;
use SConcur\Features\Mongodb\Payloads\DeleteManyPayload;
use SConcur\Features\Mongodb\Payloads\DeleteOnePayload;
use SConcur\Features\Mongodb\Payloads\DistinctPayload;
use SConcur\Features\Mongodb\Payloads\DropIndexPayload;
use SConcur\Features\Mongodb\Payloads\DropPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\EstimatedDocumentCountPayload;
use SConcur\Features\Mongodb\Payloads\FindOneAndDeletePayload;
use SConcur\Features\Mongodb\Payloads\FindOneAndReplacePayload;
use SConcur\Features\Mongodb\Payloads\FindOneAndUpdatePayload;
use SConcur\Features\Mongodb\Payloads\FindOnePayload;
use SConcur\Features\Mongodb\Payloads\FindPayload;
use SConcur\Features\Mongodb\Payloads\InsertManyPayload;
use SConcur\Features\Mongodb\Payloads\InsertOnePayload;
use SConcur\Features\Mongodb\Payloads\ListIndexesPayload;
use SConcur\Features\Mongodb\Payloads\RenameCollectionPayload;
use SConcur\Features\Mongodb\Payloads\ReplaceOnePayload;
use SConcur\Features\Mongodb\Payloads\Support\IndexName;
use SConcur\Features\Mongodb\Payloads\UpdateManyPayload;
use SConcur\Features\Mongodb\Payloads\UpdateOnePayload;
use SConcur\Features\Mongodb\Results\BulkWriteResult;
use SConcur\Features\Mongodb\Results\DeleteResult;
use SConcur\Features\Mongodb\Results\InsertManyResult;
use SConcur\Features\Mongodb\Results\InsertOneResult;
use SConcur\Features\Mongodb\Results\IteratorResult;
use SConcur\Features\Mongodb\Results\UpdateResult;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;

readonly class Collection
{
    protected Connection $connection;

    public function __construct(public Database $database, public string $name)
    {
        $this->connection = new Connection(
            uri: $this->database->client->uri,
            databaseName: $this->database->name,
            collectionName: $this->name,
            timeoutMs: $this->database->client->timeoutMs,
            serverSelectionTimeoutMs: $this->database->client->serverSelectionTimeoutMs,
        );
    }

    /**
     * @param array<int|string|float|bool|null, mixed> $document
     */
    public function insertOne(array $document): InsertOneResult
    {
        $taskResult = $this->exec(
            payload: new InsertOnePayload(
                connection: $this->connection,
                document: $document,
            ),
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
        $taskResult = $this->exec(
            payload: new InsertManyPayload(
                connection: $this->connection,
                documents: $documents,
            ),
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
        $taskResult = $this->exec(
            payload: new BulkWritePayload(
                connection: $this->connection,
                operations: $operations,
            ),
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
        $taskResult = $this->exec(
            payload: new CountDocumentsPayload(
                connection: $this->connection,
                filter: $filter,
            ),
        );

        $result = $taskResult->payload;

        if (ctype_digit($result) === false) {
            throw new InvalidCountResultException(
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
    public function aggregate(array $pipeline, int $batchSize = 50): Iterator
    {
        return new IteratorResult(
            payload: new AggregatePayload(
                connection: $this->connection,
                pipeline: $pipeline,
                batchSize: $batchSize,
            ),
        );
    }

    /**
     * @param array<string, mixed>                  $filter
     * @param array<string, mixed>                  $update
     * @param array<int, array<string, mixed>>|null $arrayFilters
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     */
    public function updateOne(
        array $filter,
        array $update,
        bool $upsert = false,
        ?array $arrayFilters = null,
        array|string|null $hint = null,
        ?array $collation = null,
    ): UpdateResult {
        return $this->buildUpdateResult(
            $this->exec(
                payload: new UpdateOnePayload(
                    connection: $this->connection,
                    filter: $filter,
                    update: $update,
                    upsert: $upsert,
                    arrayFilters: $arrayFilters,
                    hint: $hint,
                    collation: $collation,
                ),
            ),
        );
    }

    /**
     * @param array<string, mixed>                  $filter
     * @param array<string, mixed>                  $update
     * @param array<int, array<string, mixed>>|null $arrayFilters
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     */
    public function updateMany(
        array $filter,
        array $update,
        bool $upsert = false,
        ?array $arrayFilters = null,
        array|string|null $hint = null,
        ?array $collation = null,
    ): UpdateResult {
        return $this->buildUpdateResult(
            $this->exec(
                payload: new UpdateManyPayload(
                    connection: $this->connection,
                    filter: $filter,
                    update: $update,
                    upsert: $upsert,
                    arrayFilters: $arrayFilters,
                    hint: $hint,
                    collation: $collation,
                ),
            ),
        );
    }

    /**
     * @param array<string, mixed>           $filter
     * @param array<string, mixed>           $projection
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     *
     * @return array<int|string, mixed>|null
     */
    public function findOne(
        array $filter,
        ?array $projection = null,
        array|string|null $hint = null,
        ?array $collation = null,
    ): ?array {
        $taskResult = $this->exec(
            payload: new FindOnePayload(
                connection: $this->connection,
                filter: $filter,
                projection: $projection,
                hint: $hint,
                collation: $collation,
            ),
        );

        if (!$taskResult->payload) {
            return null;
        }

        return DocumentSerializer::unserialize($taskResult->payload) ?: null;
    }

    /**
     * @param array<string, mixed>           $filter
     * @param array<string, mixed>|null      $projection
     * @param array<string, int>|null        $sort
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     *
     * @return Iterator<int, array<int|string|float|bool|null, mixed>>
     */
    public function find(
        array $filter,
        ?array $projection = null,
        ?array $sort = null,
        int $limit = 0,
        int $skip = 0,
        int $batchSize = 50,
        array|string|null $hint = null,
        ?array $collation = null,
    ): Iterator {
        return new IteratorResult(
            payload: new FindPayload(
                connection: $this->connection,
                filter: $filter,
                projection: $projection,
                sort: $sort,
                limit: $limit,
                skip: $skip,
                batchSize: $batchSize,
                hint: $hint,
                collation: $collation,
            ),
        );
    }

    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>|null $collation
     *
     * @return array<int, mixed>
     */
    public function distinct(string $fieldName, array $filter = [], ?array $collation = null): array
    {
        $taskResult = $this->exec(
            payload: new DistinctPayload(
                connection: $this->connection,
                fieldName: $fieldName,
                filter: $filter,
                collation: $collation,
            ),
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return $docResult['values'] ?? [];
    }

    /**
     * @param array<string, mixed>                  $filter
     * @param array<string, mixed>                  $update
     * @param array<string, mixed>|null             $projection
     * @param array<int, array<string, mixed>>|null $arrayFilters
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     *
     * @return array<int|string, mixed>|null
     */
    public function findOneAndUpdate(
        array $filter,
        array $update,
        ?array $projection = null,
        bool $upsert = false,
        bool $returnDocument = true,
        ?array $arrayFilters = null,
        array|string|null $hint = null,
        ?array $collation = null,
    ): ?array {
        $taskResult = $this->exec(
            payload: new FindOneAndUpdatePayload(
                connection: $this->connection,
                filter: $filter,
                update: $update,
                projection: $projection,
                upsert: $upsert,
                returnDocument: $returnDocument,
                arrayFilters: $arrayFilters,
                hint: $hint,
                collation: $collation,
            ),
        );

        if (!$taskResult->payload) {
            return null;
        }

        return DocumentSerializer::unserialize($taskResult->payload) ?: null;
    }

    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>|null $projection
     *
     * @return array<int|string, mixed>|null
     */
    public function findOneAndDelete(
        array $filter,
        ?array $projection = null,
    ): ?array {
        $taskResult = $this->exec(
            payload: new FindOneAndDeletePayload(
                connection: $this->connection,
                filter: $filter,
                projection: $projection,
            ),
        );

        if (!$taskResult->payload) {
            return null;
        }

        return DocumentSerializer::unserialize($taskResult->payload) ?: null;
    }

    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>      $replacement
     * @param array<string, mixed>|null $projection
     *
     * @return array<int|string, mixed>|null
     */
    public function findOneAndReplace(
        array $filter,
        array $replacement,
        ?array $projection = null,
        bool $upsert = false,
        bool $returnDocument = true,
    ): ?array {
        $taskResult = $this->exec(
            payload: new FindOneAndReplacePayload(
                connection: $this->connection,
                filter: $filter,
                replacement: $replacement,
                projection: $projection,
                upsert: $upsert,
                returnDocument: $returnDocument,
            ),
        );

        if (!$taskResult->payload) {
            return null;
        }

        return DocumentSerializer::unserialize($taskResult->payload) ?: null;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $replacement
     */
    public function replaceOne(array $filter, array $replacement, bool $upsert = false): UpdateResult
    {
        return $this->buildUpdateResult(
            $this->exec(
                payload: new ReplaceOnePayload(
                    connection: $this->connection,
                    filter: $filter,
                    replacement: $replacement,
                    upsert: $upsert,
                ),
            ),
        );
    }

    public function estimatedDocumentCount(): int
    {
        $taskResult = $this->exec(
            payload: new EstimatedDocumentCountPayload(
                connection: $this->connection,
            ),
        );

        $result = $taskResult->payload;

        if (ctype_digit($result) === false) {
            throw new InvalidCountResultException(
                "Invalid estimatedDocumentCount result: $result"
            );
        }

        return (int) $result;
    }

    /**
     * @param array<string, int|string> $keys
     */
    public function createIndex(array $keys, ?string $name = null): string
    {
        $taskResult = $this->exec(
            payload: new CreateIndexPayload(
                connection: $this->connection,
                keys: $keys,
                name: $name,
            ),
        );

        return $taskResult->payload;
    }

    /**
     * @param array<int, array{keys: array<string, int|string>, name?: string}> $indexes
     *
     * @return array<int, string>
     */
    public function createIndexes(array $indexes): array
    {
        $taskResult = $this->exec(
            payload: new CreateIndexesPayload(
                connection: $this->connection,
                indexes: $indexes,
            ),
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return $docResult['names'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listIndexes(): array
    {
        $taskResult = $this->exec(
            payload: new ListIndexesPayload(
                connection: $this->connection,
            ),
        );

        if (!$taskResult->payload) {
            return [];
        }

        return DocumentSerializer::unserializeBatch($taskResult->payload);
    }

    /**
     * @param array<string, int|string>|string $index
     */
    public function dropIndex(array|string $index): string
    {
        $taskResult = $this->exec(
            payload: new DropIndexPayload(
                connection: $this->connection,
                index: $index,
            ),
        );

        return $taskResult->payload;
    }

    /**
     * @param array<string, mixed>           $filter
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     */
    public function deleteOne(array $filter, array|string|null $hint = null, ?array $collation = null): DeleteResult
    {
        return $this->buildDeleteResult(
            $this->exec(
                payload: new DeleteOnePayload(
                    connection: $this->connection,
                    filter: $filter,
                    hint: $hint,
                    collation: $collation,
                ),
            ),
        );
    }

    /**
     * @param array<string, mixed>           $filter
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     */
    public function deleteMany(array $filter, array|string|null $hint = null, ?array $collation = null): DeleteResult
    {
        return $this->buildDeleteResult(
            $this->exec(
                payload: new DeleteManyPayload(
                    connection: $this->connection,
                    filter: $filter,
                    hint: $hint,
                    collation: $collation,
                ),
            ),
        );
    }

    public function drop(): void
    {
        $this->exec(
            payload: new DropPayload(
                connection: $this->connection,
            ),
        );
    }

    public function rename(string $target, bool $dropTarget = false): void
    {
        $this->exec(
            payload: new RenameCollectionPayload(
                connection: $this->connection,
                target: $target,
                dropTarget: $dropTarget,
            ),
        );
    }

    /**
     * @param array<string, int|string> $keys
     */
    public function makeIndexNameByKeys(array $keys): string
    {
        return IndexName::fromKeys($keys);
    }

    protected function exec(BaseMongodbPayload $payload): TaskResultDto
    {
        return FeatureExecutor::exec(
            payload: $payload,
        );
    }

    private function buildUpdateResult(TaskResultDto $taskResult): UpdateResult
    {
        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new UpdateResult(
            matchedCount: (int) $docResult['matchedcount'],
            modifiedCount: (int) $docResult['modifiedcount'],
            upsertedCount: (int) $docResult['upsertedcount'],
            upsertedId: $docResult['upsertedid'],
        );
    }

    private function buildDeleteResult(TaskResultDto $taskResult): DeleteResult
    {
        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return new DeleteResult(
            deletedCount: (int) $docResult['deletedcount'],
        );
    }
}
