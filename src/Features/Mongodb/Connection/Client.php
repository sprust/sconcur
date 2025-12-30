<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

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
}
