<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class UpdateOnePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed>                  $filter
     * @param array<string, mixed>                  $update
     * @param array<int, array<string, mixed>>|null $arrayFilters
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     */
    public function __construct(
        private array $filter,
        private array $update,
        private bool $upsert = false,
        private ?array $arrayFilters = null,
        private array|string|null $hint = null,
        private ?array $collation = null,
    ) {
    }

    public function getData(): array
    {
        $data = [
            'f'  => $this->filter,
            'u'  => $this->update,
            'ou' => $this->upsert,
        ];

        $options = new OptionsPayloadParameters(
            hint: $this->hint,
            collation: $this->collation,
            arrayFilters: $this->arrayFilters,
        );

        return [
            ...$data,
            ...$options->getData(),
        ];
    }
}
