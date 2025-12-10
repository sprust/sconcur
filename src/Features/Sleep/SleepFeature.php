<?php

declare(strict_types=1);

namespace SConcur\Features\Sleep;

use SConcur\Entities\Context;
use SConcur\Features\MethodEnum;
use SConcur\SConcur;

readonly class SleepFeature
{
    public function sleep(Context $context, int $seconds): void
    {
        $this->usleep(context: $context, milliseconds: $seconds * 1_000);
    }

    public function usleep(Context $context, int $milliseconds): void
    {
        SConcur::getCurrentFlow()->exec(
            context: $context,
            method: MethodEnum::Sleep,
            payload: json_encode([
                'ms' => $milliseconds,
            ])
        );
    }
}
