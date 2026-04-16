<?php

declare(strict_types=1);

namespace SConcur\Features\Sleeper;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Transport\MessagePackTransport;

readonly class Sleeper
{
    public function sleep(int $seconds): void
    {
        $this->usleep(milliseconds: $seconds * 1_000);
    }

    public function usleep(int $milliseconds): void
    {
        FeatureExecutor::exec(
            method: MethodEnum::Sleep,
            payload: MessagePackTransport::pack([
                'ms' => $milliseconds,
            ])
        );
    }
}
