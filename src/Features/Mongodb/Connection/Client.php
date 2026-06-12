<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\ListDatabasesPayload;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;

readonly class Client
{
    public int $socketTimeoutMs;

    protected Connection $connection;

    public function __construct(public string $uri, ?int $socketTimeoutMs = null)
    {
        $this->socketTimeoutMs = $socketTimeoutMs ?: 30000;

        $this->connection = new Connection(
            uri: $this->uri,
            databaseName: '',
            collectionName: '',
            socketTimeoutMs: $this->socketTimeoutMs,
        );
    }

    public function selectDatabase(string $name): Database
    {
        return new Database(client: $this, name: $name);
    }

    /**
     * @return array<int, string>
     */
    public function listDatabases(): array
    {
        $taskResult = FeatureExecutor::exec(
            payload: new ListDatabasesPayload(
                connection: $this->connection,
            ),
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return $docResult['names'] ?? [];
    }
}
