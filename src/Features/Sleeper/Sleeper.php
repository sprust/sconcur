<?php

declare(strict_types=1);

namespace SConcur\Features\Sleeper;

use SConcur\Features\MethodEnum;
use SConcur\State;

readonly class Sleeper
{
    public function sleep(int $seconds): void
    {
        $this->usleep(milliseconds: $seconds * 1_000);
    }

    public function usleep(int $milliseconds): void
    {
        State::getCurrentFlow()->exec(
            method: MethodEnum::Sleep,
            payload: json_encode([
                'ms' => $milliseconds,
            ])
        );
    }
}
