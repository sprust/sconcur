<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class DistinctPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>|null $collation
     */
    public function __construct(
        private string $fieldName,
        private array $filter = [],
        private ?array $collation = null,
    ) {
    }

    public function getData(): array
    {
        $options = new OptionsPayloadParameters(
            collation: $this->collation
        );

        return [
            'fn' => $this->fieldName,
            'f'  => $this->filter,
            ...$options->getData(),
        ];
    }
}
