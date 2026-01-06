<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Sleeper;

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use Throwable;

class SleeperTest extends BaseAsyncTestCase
{
    private Sleeper $sleeper;

    private float $startTime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleeper   = new Sleeper();
        $this->startTime = microtime(true);
    }

    protected function on_1_start(): void
    {
        $this->sleeper->usleep(milliseconds: 10);
    }

    protected function on_1_middle(): void
    {
        $this->sleeper->usleep(milliseconds: 10);
    }

    protected function on_2_start(): void
    {
        $this->sleeper->usleep(milliseconds: 10);
    }

    protected function on_2_middle(): void
    {
        $this->sleeper->usleep(milliseconds: 10);
    }

    protected function on_iterate(): void
    {
        $this->sleeper->usleep(milliseconds: 1);
    }

    protected function on_exception(): void
    {
        $this->sleeper->usleep(milliseconds: -1);
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(str_contains($exception->getMessage(), 'sleep:'));
    }

    protected function assertResult(array $results): void
    {
        $totalTimeMs = (microtime(true) - $this->startTime) * 1000;

        self::assertTrue(
            $totalTimeMs >= 20,
            "Total time is less than 20ms but $totalTimeMs"
        );

        self::assertTrue(
            $totalTimeMs <= 30,
            "Total time is more than 30ms but $totalTimeMs"
        );
    }
}
