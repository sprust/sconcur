<?php

declare(strict_types=1);

namespace SConcur\Features\Sleeper;

use SConcur\Entities\Context;
use SConcur\Features\MethofEnum;
use SConcur\State;

readonly class Sleeper
{
    public function sleep(Context $context, int $seconds): void
    {
        $this->usleep(context: $context, milliseconds: $seconds * 1_000);
    }

    public function usleep(Context $context, int $milliseconds): void
    {
        State::getCurrentFlow()->exec(
            context: $context,
            method: MethofEnum::Sleep,
            payload: json_encode([
                'ms' => $milliseconds,
            ])
        );
    }
}
