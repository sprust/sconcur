<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Sleeper;

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use Throwable;

class SleeperTest extends BaseAsyncTestCase
{
    private float $startTime = 0;
    private float $endTime   = 0;

    protected function on_1_start(): void
    {
        $this->startTime = microtime(true);

        Sleeper::usleep(microseconds: 10000);
    }

    protected function on_1_middle(): void
    {
        Sleeper::usleep(microseconds: 10000);
    }

    protected function on_2_start(): void
    {
        Sleeper::usleep(microseconds: 10000);
    }

    protected function on_2_middle(): void
    {
        Sleeper::usleep(microseconds: 10000);
    }

    protected function on_iterate(): void
    {
        $this->endTime = microtime(true);

        Sleeper::usleep(microseconds: 1000);
    }

    protected function on_exception(): void
    {
        Sleeper::usleep(microseconds: -1000);
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(str_contains($exception->getMessage(), 'sleep:'));
    }

    protected function assertResult(array $results): void
    {
        // Measured from the start of the first task to the last yielded result:
        // each task sleeps 10ms twice, so concurrent execution takes >= 20ms,
        // while sequential execution would take >= 40ms.
        $totalTimeMs = ($this->endTime - $this->startTime) * 1000;

        self::assertTrue(
            $totalTimeMs >= 20,
            "Total time is less than 20ms but $totalTimeMs",
        );

        self::assertTrue(
            $totalTimeMs < 40,
            "Total time is not less than 40ms but $totalTimeMs",
        );
    }
}
