<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Sleeper;

use SConcur\Entities\Context;
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

    protected function on_1_start(Context $context): void
    {
        $this->sleeper->usleep(context: $context, milliseconds: 10);
    }

    protected function on_1_middle(Context $context): void
    {
        $this->sleeper->usleep(context: $context, milliseconds: 10);
    }

    protected function on_2_start(Context $context): void
    {
        $this->sleeper->usleep(context: $context, milliseconds: 10);
    }

    protected function on_2_middle(Context $context): void
    {
        $this->sleeper->usleep(context: $context, milliseconds: 10);
    }

    protected function on_iterate(Context $context): void
    {
        $this->sleeper->usleep(context: $context, milliseconds: 1);
    }

    protected function on_exception(Context $context): void
    {
        $this->sleeper->usleep(context: $context, milliseconds: -1);
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
