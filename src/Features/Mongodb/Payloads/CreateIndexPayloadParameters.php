<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\Payloads\Support\IndexName;
use SConcur\Transport\PayloadParametersInterface;

readonly class CreateIndexPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, int|string> $keys
     */
    public function __construct(
        private array $keys,
        private ?string $name = null,
    ) {
    }

    public function getData(): array
    {
        return [
            'k' => $this->keys,
            'n' => $this->name ?: IndexName::fromKeys($this->keys),
        ];
    }
}
