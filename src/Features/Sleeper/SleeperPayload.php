<?php

declare(strict_types=1);

namespace SConcur\Features\Sleeper;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

readonly class SleeperPayload implements PayloadInterface
{
    public function __construct(
        private int $milliseconds,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::Sleep;
    }

    /**
     * @return array<string, int>
     */
    public function getData(): int|float|string|array|null
    {
        return [
            'ms' => $this->milliseconds,
        ];
    }
}
