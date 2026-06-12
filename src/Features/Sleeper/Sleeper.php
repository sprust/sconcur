<?php

declare(strict_types=1);

namespace SConcur\Features\Sleeper;

use SConcur\Features\FeatureExecutor;

readonly class Sleeper
{
    public function sleep(int $seconds): void
    {
        $this->usleep(milliseconds: $seconds * 1_000);
    }

    public function usleep(int $milliseconds): void
    {
        FeatureExecutor::exec(
            payload: new SleeperPayload(
                milliseconds: $milliseconds,
            )
        );
    }
}
