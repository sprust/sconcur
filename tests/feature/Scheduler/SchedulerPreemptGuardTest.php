<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use Fiber;
use SConcur\Scheduler\Scheduler;
use SConcur\State;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * Deterministic regression tests for the suspend-transition guard: preempt()
 * must park a running coroutine normally, and must be a no-op while the
 * coroutine is inside a suspend transition — the stress-found races (a group
 * waiter woken onto its switch parking, a task pushed but not yet mapped) all
 * reduce to a preemption landing inside that window.
 */
class SchedulerPreemptGuardTest extends BaseTestCase
{
    public function testPreemptParksARunningCoroutine(): void
    {
        $events = [];

        $waitGroup = WaitGroup::create();

        // preempt() parks the first coroutine mid-run, so the second one gets
        // to run before the first appends its event.
        $waitGroup->add(
            callback: static function () use (&$events): void {
                Scheduler::get()->preempt();

                $events[] = 'preempted-after';
            },
        );

        $waitGroup->add(
            callback: static function () use (&$events): void {
                $events[] = 'neighbour';
            },
        );

        $waitGroup->waitAll();

        self::assertSame(
            [
                'neighbour',
                'preempted-after',
            ],
            $events,
        );

        $this->assertNoTasksCount();
    }

    public function testPreemptIsNoOpInsideSuspendTransition(): void
    {
        $events = [];

        $waitGroup = WaitGroup::create();

        // The same flow, but the coroutine is inside a suspend-transition
        // window: preempt() must not park it, so its event lands first.
        $waitGroup->add(
            callback: static function () use (&$events): void {
                $currentFiber = Fiber::getCurrent();

                self::assertNotNull($currentFiber);

                State::markSuspending(spl_object_id($currentFiber));

                try {
                    Scheduler::get()->preempt();

                    $events[] = 'guarded-after';
                } finally {
                    State::clearSuspending();
                }
            },
        );

        $waitGroup->add(
            callback: static function () use (&$events): void {
                $events[] = 'neighbour';
            },
        );

        $waitGroup->waitAll();

        self::assertSame(
            [
                'guarded-after',
                'neighbour',
            ],
            $events,
        );

        $this->assertNoTasksCount();
    }

    public function testSuspendingWindowIsPerFiber(): void
    {
        $events = [];

        $waitGroup = WaitGroup::create();

        // A foreign fiber's window must not shield this coroutine: preempt()
        // still parks it, and the neighbour runs first.
        $waitGroup->add(
            callback: static function () use (&$events): void {
                State::markSuspending(fiberId: PHP_INT_MAX);

                try {
                    Scheduler::get()->preempt();

                    $events[] = 'preempted-after';
                } finally {
                    State::clearSuspending();
                }
            },
        );

        $waitGroup->add(
            callback: static function () use (&$events): void {
                $events[] = 'neighbour';
            },
        );

        $waitGroup->waitAll();

        self::assertSame(
            [
                'neighbour',
                'preempted-after',
            ],
            $events,
        );

        $this->assertNoTasksCount();
    }
}
