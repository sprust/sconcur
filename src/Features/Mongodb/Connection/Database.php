<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\ListCollectionsPayload;
use SConcur\Features\Mongodb\Payloads\RunCommandPayload;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;

readonly class Database
{
    protected Connection $connection;

    public function __construct(public Client $client, public string $name)
    {
        $this->connection = new Connection(
            uri: $this->client->uri,
            databaseName: $this->name,
            collectionName: '',
            socketTimeoutMs: $this->client->socketTimeoutMs,
        );
    }

    public function selectCollection(string $name): Collection
    {
        return new Collection(database: $this, name: $name);
    }

    /**
     * Runs an arbitrary database command and returns the result document.
     *
     * @param array<string, mixed> $command
     *
     * @return array<int|string, mixed>
     */
    public function command(array $command): array
    {
        $taskResult = FeatureExecutor::exec(
            payload: new RunCommandPayload(
                connection: $this->connection,
                command: $command,
            ),
        );

        return DocumentSerializer::unserialize($taskResult->payload);
    }

    /**
     * @return array<int, string>
     */
    public function listCollections(): array
    {
        $taskResult = FeatureExecutor::exec(
            payload: new ListCollectionsPayload(
                connection: $this->connection,
            ),
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return $docResult['names'] ?? [];
    }
}
