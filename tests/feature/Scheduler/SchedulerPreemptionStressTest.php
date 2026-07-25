<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use SConcur\Connection\Extension;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\DestructorProbe;
use SConcur\WaitGroup;

/**
 * Stress checks for automatic preemption (see .ai/plans/coroutine-switcher.md,
 * phase 2): a high-frequency quantum interleaving with feature suspends, nested
 * groups, unwinding through finally/destructors, and arm/disarm cycling. The
 * preempt hook is armed manually here — the same plumbing the servers arm.
 */
class SchedulerPreemptionStressTest extends BaseTestCase
{
    public function testMixedCpuAndIoChaosUnderHighFrequencyPreemption(): void
    {
        Extension::get()->armPreemption(
            quantumMs: 1,
            preemptCallback: Scheduler::get()->preempt(...),
        );

        try {
            foreach (range(1, 3) as $round) {
                $finallyCount = 0;

                $waitGroup = WaitGroup::create();

                foreach (range(1, 40) as $index) {
                    $waitGroup->add(
                        callback: static function () use (&$finallyCount, $index): string {
                            try {
                                $digest = self::crunch("outer-$index", 30_000);

                                Sleeper::usleep(microseconds: 1_000 + (($index % 5) * 1_000));

                                $digest = self::crunch($digest, 30_000);

                                // A nested group: awaitGroup parking interleaves
                                // with preemption parking.
                                $nestedGroup = WaitGroup::create();

                                $nestedGroup->add(
                                    callback: static function () use ($index): string {
                                        Sleeper::usleep(microseconds: 1_000);

                                        return self::crunch("nested-$index", 10_000);
                                    },
                                );

                                $nestedResults = $nestedGroup->waitResults();

                                foreach ($nestedResults as $nestedResult) {
                                    $digest .= $nestedResult;
                                }

                                return self::crunch($digest, 10_000);
                            } finally {
                                ++$finallyCount;
                            }
                        },
                    );
                }

                $results = iterator_to_array($waitGroup->waitResults());

                self::assertCount(40, $results, "Round $round lost results.");
                self::assertSame(40, $finallyCount, "Round $round lost finally blocks.");

                foreach ($results as $result) {
                    self::assertNotSame('', $result);
                }
            }
        } finally {
            Extension::get()->disarmPreemption();
        }

        $this->assertNoTasksCount();
    }

    public function testStopUnwindsPreemptedCpuCoroutines(): void
    {
        Extension::get()->armPreemption(
            quantumMs: 1,
            preemptCallback: Scheduler::get()->preempt(...),
        );

        try {
            $finallyCount = 0;

            DestructorProbe::$destroyedCount = 0;

            $waitGroup = WaitGroup::create();

            // CPU crunchers that never finish on their own: only the stop below
            // can unwind them, through both finally blocks and destructors, while
            // preemption keeps parking and resuming them.
            foreach (range(1, 20) as $index) {
                $waitGroup->add(
                    callback: static function () use (&$finallyCount, $index): void {
                        $destructorProbe = new DestructorProbe();

                        try {
                            $digest = "cruncher-$index-" . $destructorProbe::class;

                            // Runs until the stop below unwinds it: the count
                            // never reaches the bound before the group dies.
                            while ($finallyCount < 1_000) {
                                $digest = self::crunch($digest, 5_000);
                            }
                        } finally {
                            ++$finallyCount;
                        }
                    },
                );
            }

            // The stopper runs as a member too: by the time it executes, the
            // crunchers are parked (preempted) or awaiting nothing else.
            $waitGroup->add(
                callback: static function () use ($waitGroup): void {
                    Sleeper::usleep(microseconds: 30_000);

                    $waitGroup->stop();
                },
            );

            $waitGroup->waitAll();

            self::assertSame(20, $finallyCount);
            self::assertSame(20, DestructorProbe::$destroyedCount);
            self::assertFalse($waitGroup->isLive());
        } finally {
            Extension::get()->disarmPreemption();
        }

        $this->assertNoTasksCount();
    }

    public function testArmDisarmCyclingIsStable(): void
    {
        $invokedTotal = 0;

        foreach (range(1, 200) as $cycle) {
            Extension::get()->armPreemption(
                quantumMs: 1,
                preemptCallback: static function () use (&$invokedTotal): void {
                    ++$invokedTotal;
                },
            );

            self::crunch("cycle-$cycle", 30_000);

            Extension::get()->disarmPreemption();
        }

        // Some cycles are too short for the 1ms timer to land — the requirement
        // is that heavy cycling neither crashes nor leaks, and the plumbing
        // still fires overall.
        self::assertGreaterThan(0, $invokedTotal);

        $this->assertNoTasksCount();
    }

    private static function crunch(string $seed, int $iterations): string
    {
        $digest = $seed;

        for ($i = 0; $i < $iterations; $i++) {
            $digest = hash('sha256', $digest);
        }

        return $digest;
    }
}
