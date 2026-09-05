<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads\Base;

use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\Transport\PayloadInterface;

/**
 * Builds the command envelope (ul/db/cl/to/sst/cm/dt) every MongoDB payload sends.
 *
 * Rust: payloads::Payload (ext/src/features/mongodb/payloads.rs).
 */
abstract readonly class BaseMongodbPayload implements PayloadInterface
{
    abstract protected function getCommand(): CommandEnum;

    abstract protected function getConnection(): Connection;

    abstract protected function getParameters(): Parameters;

    public function getMethod(): MethodEnum
    {
        return MethodEnum::Mongodb;
    }

    public function getData(): array
    {
        $connection = $this->getConnection();
        $parameters = $this->getParameters();

        return [
            'ul'  => $connection->uri,
            'db'  => $connection->databaseName,
            'cl'  => $connection->collectionName,
            'to'  => $connection->timeoutMs,
            'sst' => $connection->serverSelectionTimeoutMs,
            'cm'  => $this->getCommand()->value,
            'dt'  => DocumentSerializer::serialize($parameters->payload->getData()),
        ];
    }
}
