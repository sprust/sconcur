<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Parameters;

readonly class ConnectionParameters
{
    public function __construct(
        public string $uri,
        public string $database,
        public string $collection,
        public int $socketTimeoutMs = 30000, // 30 seconds
    ) {
    }
}
