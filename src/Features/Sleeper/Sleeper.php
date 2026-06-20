<?php

declare(strict_types=1);

namespace SConcur\Features\Sleeper;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\Sleeper\Payloads\SleeperPayload;

/**
 * Async, cooperative counterparts of PHP's native sleep()/usleep(): they suspend the
 * current coroutine (the scheduler runs other work) instead of blocking the thread.
 * Signatures mirror the native functions — sleep() in seconds, usleep() in microseconds.
 */
class Sleeper
{
    public static function sleep(int $seconds): void
    {
        self::usleep(microseconds: $seconds * 1_000_000);
    }

    public static function usleep(int $microseconds): void
    {
        FeatureExecutor::exec(
            payload: new SleeperPayload(
                microseconds: $microseconds,
            ),
        );
    }
}
