<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\Transport\MessagePackTransport;

readonly class Database
{
    public function __construct(public Client $client, public string $name)
    {
    }

    public function selectCollection(string $name): Collection
    {
        return new Collection(database: $this, name: $name);
    }

    /**
     * @return array<int, string>
     */
    public function listCollections(): array
    {
        $taskResult = FeatureExecutor::exec(
            method: MethodEnum::MongodbCollection,
            payload: $this->serializePayload(
                command: CommandEnum::ListCollections,
                data: DocumentSerializer::serialize([]),
            ),
        );

        $docResult = DocumentSerializer::unserialize($taskResult->payload);

        return $docResult['names'] ?? [];
    }

    protected function serializePayload(CommandEnum $command, string $data): string
    {
        return MessagePackTransport::pack([
            'ul'  => $this->client->uri,
            'db'  => $this->name,
            'cl'  => '',
            'sto' => $this->client->socketTimeoutMs,
            'cm'  => $command->value,
            'dt'  => $data,
        ]);
    }
}
