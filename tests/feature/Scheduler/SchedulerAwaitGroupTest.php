<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use Fiber;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * Scheduler::awaitGroup parks a coroutine until a nested group settles. Its wake
 * is one-shot: it fires when the group settles and only reaches a waiter that is
 * already registered. So a group that settled before the registration must never
 * be awaited — the wake for it has already happened and will not come again, and
 * the suspend would hang forever.
 *
 * The window is real: automatic preemption can park the coroutine between
 * iterate()'s liveness check and the registration, and the scheduler keeps
 * delivering results while it is parked.
 *
 * Each case asserts the fiber terminated rather than suspended — a regression
 * fails the assertion instead of hanging the suite.
 */
class SchedulerAwaitGroupTest extends BaseTestCase
{
    public function testReturnsInsteadOfParkingWhenTheGroupAlreadyHasAResult(): void
    {
        $waitGroup = WaitGroup::create();

        // A fully synchronous callback settles inside add(): its value goes
        // straight to the ready queue, so the group has a result and no members.
        $waitGroup->add(
            callback: static fn(): string => 'already-done',
        );

        self::assertTrue($waitGroup->hasReadyOrFailure());

        $fiber = $this->awaitInsideFiber($waitGroup);

        self::assertTrue(
            $fiber->isTerminated(),
            'awaitGroup parked on a group that had already settled — that suspend never wakes',
        );
    }

    public function testReturnsInsteadOfParkingWhenTheGroupHasNoLiveMembers(): void
    {
        $waitGroup = WaitGroup::create();

        self::assertFalse($waitGroup->isLive());

        $fiber = $this->awaitInsideFiber($waitGroup);

        self::assertTrue(
            $fiber->isTerminated(),
            'awaitGroup parked on a group with nothing left to wait for',
        );
    }

    /**
     * The guard must not swallow the normal case: a group that is still running
     * has a wake coming, so the coroutine is expected to park and be registered
     * as its waiter.
     */
    public function testStillParksOnALiveGroup(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): string {
                Sleeper::usleep(microseconds: 20_000);

                return 'slow';
            },
        );

        $fiber = $this->awaitInsideFiber($waitGroup);

        self::assertTrue(
            $fiber->isSuspended(),
            'a live group must still park its waiter — otherwise iterate() spins',
        );

        // Release the registered waiter and the in-flight member before the
        // scheduler's registries are checked in tearDown.
        Scheduler::get()->clearGroupWaiter($waitGroup->key());

        $waitGroup->stop();
    }

    protected function awaitInsideFiber(WaitGroup $waitGroup): Fiber
    {
        $fiber = new Fiber(static function () use ($waitGroup): void {
            Scheduler::get()->awaitGroup($waitGroup);
        });

        $fiber->start();

        return $fiber;
    }
}
