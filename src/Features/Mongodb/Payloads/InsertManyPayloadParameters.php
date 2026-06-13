<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class InsertManyPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<int, array<int|string|float|bool|null, mixed>> $documents
     */
    public function __construct(
        private array $documents,
    ) {
    }

    public function getData(): array
    {
        return $this->documents;
    }
}
