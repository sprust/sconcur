<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class AggregatePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<int, array<string, mixed>> $pipeline
     */
    public function __construct(
        private array $pipeline,
        private int $batchSize,
    ) {
    }

    public function getData(): array
    {
        return [
            'p'  => $this->pipeline,
            'bs' => $this->batchSize,
        ];
    }
}
