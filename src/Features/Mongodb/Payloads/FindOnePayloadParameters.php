<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class FindOnePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed>           $filter
     * @param array<string, mixed>|null      $projection
     * @param array<string, int>|string|null $hint
     * @param array<string, mixed>|null      $collation
     */
    public function __construct(
        private array $filter,
        private ?array $projection = null,
        private array|string|null $hint = null,
        private ?array $collation = null,
    ) {
    }

    public function getData(): array
    {
        $data = ['f' => $this->filter];

        if ($this->projection !== null) {
            $data['op'] = $this->projection;
        }

        $options = new OptionsPayloadParameters(
            hint: $this->hint,
            collation: $this->collation,
        );

        return [
            ...$data,
            ...$options->getData(),
        ];
    }
}
