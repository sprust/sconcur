<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Connection;

readonly class Database
{
    public function __construct(public Client $client, public string $name)
    {
    }

    public function selectCollection(string $name): Collection
    {
        return new Collection(database: $this, name: $name);
    }
}
