<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

readonly class Client
{
    public function __construct(
        public string $uri,
        public int $socketTimeoutMs = 30000, // 30 seconds
    ) {
    }

    public function selectDatabase(string $name): Database
    {
        return new Database(client: $this, name: $name);
    }
}
