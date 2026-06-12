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
use SConcur\Transport\MessagePackTransport;

readonly class Collection
{
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
        $serialized = DocumentSerializer::serialize($documents, isObject: false);

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
                        'document' => DocumentSerializer::serialize($value[0] ?: []),
                    ],
                    'updateOne', 'updateMany' => [
                        'filter' => DocumentSerializer::serialize($value[0] ?: []),
                        'update' => DocumentSerializer::serialize($value[1] ?: []),
                        'upsert' => $value[2]['upsert'] ?? false, // TODO
                    ],
                    'deleteOne', 'deleteMany' => [
                        'filter' => DocumentSerializer::serialize($value[0] ?: []),
                    ],
                    'replaceOne' => [
                        'filter'      => DocumentSerializer::serialize($value[0] ?: []),
                        'replacement' => DocumentSerializer::serialize($value[1] ?: []),
                        'upsert'      => $value[2]['upsert'] ?? false, // TODO
                    ],
                    default => throw new InvalidMongodbBulkWriteOperationException(
                        operationType: (string) $type
                    )
                },
            ];
        }

        $serialized = MessagePackTransport::pack($preparedOperations);

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
    public function aggregate(array $pipeline, int $batchSize = 50): Iterator
    {
        $serialized = MessagePackTransport::pack([
            'p'  => DocumentSerializer::serialize($pipeline, isObject: false),
            'bs' => $batchSize,
        ]);

        return new IteratorResult(
            method: $this->method,
            payload: $this->serializePayload(
                command: CommandEnum::Aggregate,
                data: $serialized,
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
        return $this->update(
            isMany: false,
            filter: $filter,
            update: $update,
            upsert: $upsert,
            arrayFilters: $arrayFilters,
            hint: $hint,
            collation: $collation,
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
        return $this->update(
            isMany: true,
            filter: $filter,
            update: $update,
            upsert: $upsert,
            arrayFilters: $arrayFilters,
            hint: $hint,
            collation: $collation,
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
        $serialized = MessagePackTransport::pack([
            'f'  => DocumentSerializer::serialize($filter),
            'op' => ($projection === null) ? "" : DocumentSerializer::serialize($projection),
        ] + $this->encodeOptions(hint: $hint, collation: $collation));

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
        $serialized = MessagePackTransport::pack([
            'f'  => DocumentSerializer::serialize($filter),
            'op' => ($projection === null) ? "" : DocumentSerializer::serialize($projection),
            's'  => ($sort === null) ? "" : DocumentSerializer::serialize($sort),
            'l'  => $limit,
            'sk' => $skip,
            'bs' => $batchSize,
        ] + $this->encodeOptions(hint: $hint, collation: $collation));

        return new IteratorResult(
            method: $this->method,
            payload: $this->serializePayload(
                command: CommandEnum::Find,
                data: $serialized,
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
        $serialized = MessagePackTransport::pack([
            'fn' => $fieldName,
            'f'  => DocumentSerializer::serialize($filter),
        ] + $this->encodeOptions(collation: $collation));

        $taskResult = $this->exec(
            command: CommandEnum::Distinct,
            payload: $serialized,
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
        $serialized = MessagePackTransport::pack([
            'f'  => DocumentSerializer::serialize($filter),
            'u'  => DocumentSerializer::serialize($update),
            'op' => ($projection === null) ? "" : DocumentSerializer::serialize($projection),
            'ou' => $upsert,
            'rd' => $returnDocument,
        ] + $this->encodeOptions(hint: $hint, collation: $collation, arrayFilters: $arrayFilters));

        $taskResult = $this->exec(
            command: CommandEnum::FindOneAndUpdate,
            payload: $serialized,
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
        $serialized = MessagePackTransport::pack([
            'f'  => DocumentSerializer::serialize($filter),
            'op' => ($projection === null) ? "" : DocumentSerializer::serialize($projection),
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::FindOneAndDelete,
            payload: $serialized,
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
        $serialized = MessagePackTransport::pack([
            'f'  => DocumentSerializer::serialize($filter),
            'r'  => DocumentSerializer::serialize($replacement),
            'op' => ($projection === null) ? "" : DocumentSerializer::serialize($projection),
            'ou' => $upsert,
            'rd' => $returnDocument,
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::FindOneAndReplace,
            payload: $serialized,
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
        $serialized = MessagePackTransport::pack([
            'f'  => DocumentSerializer::serialize($filter),
            'r'  => DocumentSerializer::serialize($replacement),
            'ou' => $upsert,
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::ReplaceOne,
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

    public function estimatedDocumentCount(): int
    {
        $taskResult = $this->exec(
            command: CommandEnum::EstimatedDocumentCount,
            payload: DocumentSerializer::serialize([]),
        );

        $result = $taskResult->payload;

        if (ctype_digit($result) === false) {
            throw new RuntimeException(
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
        if ($name) {
            $indexName = $name;
        } else {
            $indexName = $this->makeIndexNameByKeys($keys);
        }

        $serialized = MessagePackTransport::pack([
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
     * @param array<int, array{keys: array<string, int|string>, name?: string}> $indexes
     *
     * @return array<int, string>
     */
    public function createIndexes(array $indexes): array
    {
        $preparedIndexes = [];

        foreach ($indexes as $index) {
            $keys = $index['keys'];
            $name = $index['name'] ?? $this->makeIndexNameByKeys($keys);

            $preparedIndexes[] = [
                'k' => DocumentSerializer::serialize($keys),
                'n' => $name,
            ];
        }

        $serialized = MessagePackTransport::pack([
            'ix' => $preparedIndexes,
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::CreateIndexes,
            payload: $serialized,
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
            command: CommandEnum::ListIndexes,
            payload: DocumentSerializer::serialize([]),
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
        if (is_string($index)) {
            $indexName = $index;
        } else {
            $indexName = $this->makeIndexNameByKeys($index);
        }

        $serialized = MessagePackTransport::pack([
            'n' => $indexName,
        ]);

        $taskResult = $this->exec(
            command: CommandEnum::DropIndex,
            payload: $serialized,
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
        $serialized = MessagePackTransport::pack([
            'f' => DocumentSerializer::serialize($filter),
        ] + $this->encodeOptions(hint: $hint, collation: $collation));

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
     * @param array<string, mixed>           $filter
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     */
    public function deleteMany(array $filter, array|string|null $hint = null, ?array $collation = null): DeleteResult
    {
        $serialized = MessagePackTransport::pack([
            'f' => DocumentSerializer::serialize($filter),
        ] + $this->encodeOptions(hint: $hint, collation: $collation));

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
            payload: DocumentSerializer::serialize([]),
        );
    }

    public function rename(string $target, bool $dropTarget = false): void
    {
        $serialized = MessagePackTransport::pack([
            't'  => $target,
            'dt' => $dropTarget,
        ]);

        $this->exec(
            command: CommandEnum::RenameCollection,
            payload: $serialized,
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
     * @param array<string, mixed>                  $filter
     * @param array<string, mixed>                  $update
     * @param array<int, array<string, mixed>>|null $arrayFilters
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     */
    protected function update(
        bool $isMany,
        array $filter,
        array $update,
        bool $upsert = false,
        ?array $arrayFilters = null,
        array|string|null $hint = null,
        ?array $collation = null,
    ): UpdateResult {
        $serialized = MessagePackTransport::pack([
            'f'  => DocumentSerializer::serialize($filter),
            'u'  => DocumentSerializer::serialize($update),
            'ou' => $upsert,
        ] + $this->encodeOptions(hint: $hint, collation: $collation, arrayFilters: $arrayFilters));

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

    /**
     * Encodes optional query options into payload fields, omitting any not provided.
     *
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     * @param array<int, array<string, mixed>>|null $arrayFilters
     *
     * @return array<string, string>
     */
    protected function encodeOptions(
        array|string|null $hint = null,
        ?array $collation = null,
        ?array $arrayFilters = null,
    ): array {
        $options = [];

        if ($hint !== null) {
            $options['hn'] = DocumentSerializer::serialize(['v' => $hint]);
        }

        if ($collation !== null) {
            $options['co'] = DocumentSerializer::serialize($collation);
        }

        if ($arrayFilters !== null) {
            $options['af'] = DocumentSerializer::serialize($arrayFilters, isObject: false);
        }

        return $options;
    }

    protected function serializePayload(CommandEnum $command, string $data): string
    {
        return MessagePackTransport::pack([
            'ul'  => $this->uri,
            'db'  => $this->databaseName,
            'cl'  => $this->collectionName,
            'sto' => $this->socketTimeoutMs,
            'cm'  => $command->value,
            'dt'  => $data,
        ]);
    }
}
