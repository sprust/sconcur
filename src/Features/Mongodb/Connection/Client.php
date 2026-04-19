<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\Transport\MessagePackTransport;

readonly class Client
{
    public int $socketTimeoutMs;

    public function __construct(public string $uri, ?int $socketTimeoutMs = null)
    {
        $this->socketTimeoutMs = $socketTimeoutMs ?: 30000;
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
            method: MethodEnum::MongodbCollection,
            payload: $this->serializePayload(
                command: CommandEnum::ListDatabases,
                data: DocumentSerializer::serialize([]),
            ),
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return $docResult['names'] ?? [];
    }

    protected function serializePayload(CommandEnum $command, string $data): string
    {
        return MessagePackTransport::pack([
            'ul'  => $this->uri,
            'db'  => '',
            'cl'  => '',
            'sto' => $this->socketTimeoutMs,
            'cm'  => $command->value,
            'dt'  => $data,
        ]);
    }
}
