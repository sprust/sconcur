<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql\Connection;

class Transaction
{
    protected ?string $key = null;

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(?string $key): void
    {
        $this->key = $key;
    }
}
