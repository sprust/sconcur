<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class InsertOnePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<int|string|float|bool|null, mixed> $document
     */
    public function __construct(
        private array $document,
    ) {
    }

    public function getData(): array
    {
        return $this->document;
    }
}
