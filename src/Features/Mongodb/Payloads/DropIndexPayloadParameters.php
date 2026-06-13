<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\Payloads\Support\IndexName;
use SConcur\Transport\PayloadParametersInterface;

readonly class DropIndexPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, int|string>|string $index
     */
    public function __construct(
        private array|string $index,
    ) {
    }

    public function getData(): array
    {
        return [
            'n' => is_string($this->index) ? $this->index : IndexName::fromKeys($this->index),
        ];
    }
}
