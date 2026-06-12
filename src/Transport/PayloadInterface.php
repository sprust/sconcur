<?php

namespace SConcur\Transport;

use SConcur\Features\MethodEnum;

interface PayloadInterface
{
    public function getMethod(): MethodEnum;

    /**
     * @return array<int|string, mixed>|int|float|string|null
     */
    public function getData(): int|float|string|array|null;
}
