<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class DeleteOnePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed>           $filter
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     */
    public function __construct(
        private array $filter,
        private array|string|null $hint = null,
        private ?array $collation = null,
    ) {
    }

    public function getData(): array
    {
        $optionsPayloadParameters = new OptionsPayloadParameters(
            hint: $this->hint,
            collation: $this->collation,
        );

        return [
            'f' => $this->filter,
            ...$optionsPayloadParameters->getData(),
        ];
    }
}
