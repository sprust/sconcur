<?php

declare(strict_types=1);

namespace SConcur\Flow;

readonly class CurrentFlow
{
    public function __construct(
        public bool $isAsync,
        public string $key,
    ) {
    }
}
