<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads\Dto;

readonly class Connection
{
    public function __construct(
        public string $uri,
        public string $databaseName,
        public string $collectionName,
        public int $socketTimeoutMs,
    ) {
    }
}
