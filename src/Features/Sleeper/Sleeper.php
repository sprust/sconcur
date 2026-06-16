<?php

declare(strict_types=1);

namespace SConcur\Features\Sleeper;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\Sleeper\Payloads\SleeperPayload;

readonly class Sleeper
{
    public function sleep(int $seconds): void
    {
        $this->msleep(milliseconds: $seconds * 1_000);
    }

    public function msleep(int $milliseconds): void
    {
        FeatureExecutor::exec(
            payload: new SleeperPayload(
                milliseconds: $milliseconds,
            ),
        );
    }
}
