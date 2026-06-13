<?php

namespace SConcur\Transport;

use SConcur\Features\MethodEnum;

interface PayloadInterface
{
    public function getMethod(): MethodEnum;

    /**
     * @return array<int|string, mixed>
     */
    public function getData(): array;
}
