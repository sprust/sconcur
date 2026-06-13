<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class CountDocumentsPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed> $filter
     */
    public function __construct(
        private array $filter,
    ) {
    }

    public function getData(): array
    {
        return $this->filter;
    }
}
