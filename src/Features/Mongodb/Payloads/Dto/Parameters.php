<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads\Dto;

use SConcur\Transport\PayloadParametersInterface;

readonly class Parameters
{
    public function __construct(
        public PayloadParametersInterface $payload,
        public bool $isObject,
    ) {
    }
}
