<?php

declare(strict_types=1);

namespace SConcur\Features\Sleeper\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * Rust: payloads::SleeperPayload (ext/src/features/sleeper/payloads.rs).
 */
readonly class SleeperPayload implements PayloadInterface
{
    public function __construct(
        protected int $microseconds,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::Sleep;
    }

    /**
     * @return array<string, int>
     */
    public function getData(): array
    {
        return [
            'us' => $this->microseconds,
        ];
    }
}
