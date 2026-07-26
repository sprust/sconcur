<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use SConcur\Connection\Extension;
use SConcur\Tests\Feature\BaseTestCase;

class ExtensionPreemptionTest extends BaseTestCase
{
    public function testInterruptTimerInvokesCallbackDuringBusyLoop(): void
    {
        $invokedCount = 0;

        Extension::get()->armPreemption(
            quantumMs: 2,
            preemptCallback: static function () use (&$invokedCount): void {
                ++$invokedCount;
            },
        );

        $digest = '';

        try {
            $deadline = microtime(true) + 0.1;

            while (microtime(true) < $deadline) {
                $digest = hash('sha256', $digest);
            }
        } finally {
            Extension::get()->disarmPreemption();
        }

        self::assertGreaterThan(0, $invokedCount);

        // Disarmed: the timer is stopped, the callback never fires again.
        $frozenCount = $invokedCount;
        $deadline    = microtime(true) + 0.05;

        while (microtime(true) < $deadline) {
            $digest = hash('sha256', $digest);
        }

        self::assertNotSame('', $digest);
        self::assertSame($frozenCount, $invokedCount);
    }
}
