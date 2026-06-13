<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class FindOneAndDeletePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>|null $projection
     */
    public function __construct(
        private array $filter,
        private ?array $projection = null,
    ) {
    }

    public function getData(): array
    {
        $data = ['f' => $this->filter];

        if ($this->projection !== null) {
            $data['op'] = $this->projection;
        }

        return $data;
    }
}
