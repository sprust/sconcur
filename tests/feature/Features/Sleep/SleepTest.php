<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Sleep;

use SConcur\Entities\Context;
use SConcur\Features\Features;
use SConcur\Features\Sleep\SleepFeature;
use SConcur\Tests\Feature\BaseAsyncTestCase;

class SleepTest extends BaseAsyncTestCase
{
    private SleepFeature $sleepFeature;

    private float $startTime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleepFeature = Features::sleep();
        $this->startTime    = microtime(true);
    }

    protected function on_1_start(Context $context): void
    {
        $this->sleepFeature->usleep(context: $context, milliseconds: 10);
    }

    protected function on_1_middle(Context $context): void
    {
        $this->sleepFeature->usleep(context: $context, milliseconds: 10);
    }

    protected function on_2_start(Context $context): void
    {
        $this->sleepFeature->usleep(context: $context, milliseconds: 10);
    }

    protected function on_2_middle(Context $context): void
    {
        $this->sleepFeature->usleep(context: $context, milliseconds: 10);
    }

    protected function on_iterate(Context $context): void
    {
        $this->sleepFeature->usleep(context: $context, milliseconds: 1);
    }

    protected function assertResult(array $results): void
    {
        $totalTimeMs = (microtime(true) - $this->startTime) * 1000;

        self::assertTrue(
            $totalTimeMs >= 20
        );

        self::assertTrue(
            $totalTimeMs <= 30
        );
    }
}
