<?php

declare(strict_types=1);

namespace SConcur\Logging;

use SConcur\SConcur;

class LoggerFormatter
{
    public static function make(string $message, ?string $taskKey = null): string
    {
        return sprintf(
            "[flow: %s%s]: %s",
            SConcur::getCurrentFlow()->getUuid(),
            ($taskKey ? ", task: $taskKey" : ''),
            $message
        );
    }
}
