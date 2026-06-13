<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads\Base;

use SConcur\Features\MethodEnum;
use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;
use SConcur\Transport\PayloadInterface;

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
            'dt'  => DocumentSerializer::serialize(
                document: $parameters->data,
                isObject: $parameters->isObject,
            ),
        ];
    }

    /**
     * Encodes optional query options into nested payload fields, omitting any not provided.
     * The whole payload is serialized to BSON once by getData(), so values stay raw here.
     *
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     * @param array<int, array<string, mixed>>|null $arrayFilters
     *
     * @return array<string, mixed>
     */
    protected function encodeOptions(
        array|string|null $hint = null,
        ?array $collation = null,
        ?array $arrayFilters = null,
    ): array {
        $options = [];

        if ($hint !== null) {
            $options['hn'] = ['v' => $hint];
        }

        if ($collation !== null) {
            $options['co'] = $collation;
        }

        if ($arrayFilters !== null) {
            $options['af'] = $arrayFilters;
        }

        return $options;
    }
}
