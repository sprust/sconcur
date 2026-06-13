<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class ReplaceOnePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $replacement
     */
    public function __construct(
        private array $filter,
        private array $replacement,
        private bool $upsert = false,
    ) {
    }

    public function getData(): array
    {
        return [
            'f'  => $this->filter,
            'r'  => $this->replacement,
            'ou' => $this->upsert,
        ];
    }
}
