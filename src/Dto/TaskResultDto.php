<?php

declare(strict_types=1);

namespace SConcur\Dto;

use SConcur\Features\FeatureEnum;

readonly class TaskResultDto
{
    public function __construct(
        public string $flowKey,
        public FeatureEnum $method,
        public string $key,
        public bool $isError,
        public string $payload,
        public bool $hasNext,
    ) {
    }
}
