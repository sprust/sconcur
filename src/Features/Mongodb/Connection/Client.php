<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\ListDatabasesPayload;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;

readonly class Client
{
    public int $timeoutMs;

    public int $serverSelectionTimeoutMs;

    protected Connection $connection;

    public function __construct(
        public string $uri,
        ?int $timeoutMs = null,
        ?int $serverSelectionTimeoutMs = null,
    ) {
        $this->timeoutMs                = $timeoutMs ?: 30000;
        $this->serverSelectionTimeoutMs = $serverSelectionTimeoutMs ?: 10000;

        $this->connection = new Connection(
            uri: $this->uri,
            databaseName: '',
            collectionName: '',
            timeoutMs: $this->timeoutMs,
            serverSelectionTimeoutMs: $this->serverSelectionTimeoutMs,
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

        $documentResult = DocumentSerializer::unserialize($taskResult->payload);

        return $documentResult['names'] ?? [];
    }
}
