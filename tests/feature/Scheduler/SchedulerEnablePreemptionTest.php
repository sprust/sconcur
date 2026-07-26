<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * The Scheduler-level preemption switch (enablePreemption/disablePreemption —
 * the convenience wrapper over Extension::armPreemption + the preempt hook):
 * with it on, a CPU loop that never calls switch() stops starving its
 * neighbours; with it off, the same loop runs to completion first.
 */
class SchedulerEnablePreemptionTest extends BaseTestCase
{
    public function testEnabledPreemptionInterleavesNonYieldingCpuLoop(): void
    {
        Scheduler::get()->enablePreemption(quantumMs: 1);

        try {
            $events = $this->runHeavyAndNeighbour();
        } finally {
            Scheduler::get()->disablePreemption();
        }

        self::assertSame(
            [
                'neighbour',
                'heavy-done',
            ],
            $events,
            'With preemption enabled the neighbour must run before the non-yielding CPU loop finishes.',
        );

        $this->assertNoTasksCount();
    }

    public function testWithoutPreemptionNonYieldingCpuLoopRunsFirst(): void
    {
        $events = $this->runHeavyAndNeighbour();

        self::assertSame(
            [
                'heavy-done',
                'neighbour',
            ],
            $events,
            'Without preemption the non-yielding CPU loop must block its neighbour until it finishes.',
        );

        $this->assertNoTasksCount();
    }

    /**
     * Runs a non-yielding sha256 loop (~100 ms, no switch() calls) alongside a
     * trivial neighbour coroutine and returns the completion events in order.
     *
     * @return list<string>
     */
    protected function runHeavyAndNeighbour(): array
    {
        $events = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function () use (&$events): void {
                $digest = 'seed';

                for ($iteration = 0; $iteration < 300_000; $iteration++) {
                    $digest = hash('sha256', $digest);
                }

                $events[] = 'heavy-done';
            },
        );

        $waitGroup->add(
            callback: static function () use (&$events): void {
                $events[] = 'neighbour';
            },
        );

        $waitGroup->waitAll();

        return $events;
    }
}
