<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class OptionsPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<int, array<string, mixed>>|null $arrayFilters
     * @param array<string, int>|string|null        $hint
     * @param array<string, mixed>|null             $collation
     */
    public function __construct(
        private array|string|null $hint = null,
        private ?array $collation = null,
        private ?array $arrayFilters = null,
    ) {
    }

    public function getData(): array
    {
        $data = [];

        if ($this->hint !== null) {
            $data['hn'] = ['v' => $this->hint];
        }

        if ($this->collation !== null) {
            $data['co'] = $this->collation;
        }

        if ($this->arrayFilters !== null) {
            $data['af'] = $this->arrayFilters;
        }

        return $data;
    }
}
