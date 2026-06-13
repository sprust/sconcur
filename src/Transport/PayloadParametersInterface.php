<?php

namespace SConcur\Transport;

interface PayloadParametersInterface
{
    /**
     * @return array<int|string, mixed>
     */
    public function getData(): array;
}
