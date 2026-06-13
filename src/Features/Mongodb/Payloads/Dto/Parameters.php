<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads\Dto;

readonly class Parameters
{
    /**
     * @param array<int|string, mixed> $data
     */
    public function __construct(
        public array $data,
        public bool $isObject,
    ) {
    }
}
