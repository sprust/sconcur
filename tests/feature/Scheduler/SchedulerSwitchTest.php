<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

class SchedulerSwitchTest extends BaseTestCase
{
    public function testSwitchOutsideFiberIsNoOp(): void
    {
        self::assertFalse(Scheduler::get()->switch());
        self::assertFalse(Scheduler::get()->switch(quantumMs: 0));
    }

    public function testCpuLoopInterleavesWithNeighbourCoroutine(): void
    {
        $events = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function () use (&$events): void {
                $scheduler = Scheduler::get();

                foreach (range(1, 3) as $iteration) {
                    $events[] = "cpu-$iteration";

                    $scheduler->switch(quantumMs: 0);
                }
            },
        );

        $waitGroup->add(
            callback: static function () use (&$events): void {
                $events[] = 'neighbour';
            },
        );

        $waitGroup->waitAll();

        $neighbourPosition = array_search('neighbour', $events, true);

        self::assertIsInt($neighbourPosition);
        self::assertLessThan(
            count($events) - 1,
            $neighbourPosition,
            'The neighbour coroutine must run between the CPU iterations, not after them all.',
        );

        $this->assertNoTasksCount();
    }

    public function testTwoCpuLoopsRoundRobin(): void
    {
        $events = [];

        $waitGroup = WaitGroup::create();

        foreach (['first', 'second'] as $name) {
            $waitGroup->add(
                callback: static function () use (&$events, $name): void {
                    $scheduler = Scheduler::get();

                    foreach (range(1, 3) as $iteration) {
                        $events[] = "$name-$iteration";

                        $scheduler->switch(quantumMs: 0);
                    }
                },
            );
        }

        $waitGroup->waitAll();

        $secondFirstPosition = array_search('second-1', $events, true);
        $firstLastPosition   = array_search('first-3', $events, true);

        self::assertIsInt($secondFirstPosition);
        self::assertIsInt($firstLastPosition);
        self::assertLessThan(
            $firstLastPosition,
            $secondFirstPosition,
            'The second CPU loop must start before the first one finishes.',
        );

        $this->assertNoTasksCount();
    }

    public function testQuantumHoldsBackFrequentCalls(): void
    {
        $switchResults = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function () use (&$switchResults): void {
                $scheduler = Scheduler::get();

                // A large quantum: the first call only starts it, an immediate
                // second call is still inside it — neither yields.
                $switchResults[] = $scheduler->switch(quantumMs: 60_000);
                $switchResults[] = $scheduler->switch(quantumMs: 60_000);

                // A forced switch yields regardless of the quantum.
                $switchResults[] = $scheduler->switch(quantumMs: 0);
            },
        );

        $waitGroup->waitAll();

        self::assertSame(
            [
                false,
                false,
                true,
            ],
            $switchResults,
        );

        $this->assertNoTasksCount();
    }

    public function testQuantumSharesCpuFairlyBetweenTwoLoops(): void
    {
        // Two identical CPU loops with a real (non-zero) quantum must end up
        // with comparable iteration counts. Regression: when the quantum was
        // measured from the park instead of the resume, the queue waiting time
        // ate the next quantum and the shares skewed to roughly 9:1.
        $iterationCounts = [
            'first'  => 0,
            'second' => 0,
        ];

        $deadlineNs = hrtime(true) + 200_000_000;

        $waitGroup = WaitGroup::create();

        foreach (['first', 'second'] as $name) {
            $waitGroup->add(
                callback: static function () use (&$iterationCounts, $name, $deadlineNs): void {
                    $scheduler = Scheduler::get();

                    $digest = $name;

                    while (hrtime(true) < $deadlineNs) {
                        $scheduler->switch(quantumMs: 5);

                        $digest = hash('sha256', $digest);

                        ++$iterationCounts[$name];
                    }
                },
            );
        }

        $waitGroup->waitAll();

        $maxIterations = max($iterationCounts);
        $minIterations = min($iterationCounts);

        self::assertGreaterThan(0, $minIterations);
        self::assertLessThan(
            $minIterations * 2,
            $maxIterations,
            sprintf(
                'The CPU shares diverged (%s): the switch quantum is not fair.',
                json_encode($iterationCounts),
            ),
        );

        $this->assertNoTasksCount();
    }

    public function testStopUnwindsParkedCoroutine(): void
    {
        $finallyRan = false;

        $waitGroup = WaitGroup::create();

        // Parks itself until unwound: only the group stop below ends the loop.
        $waitGroup->add(
            callback: static function () use (&$finallyRan): void {
                try {
                    $scheduler = Scheduler::get();

                    while (!$finallyRan) {
                        $scheduler->switch(quantumMs: 0);
                    }
                } finally {
                    $finallyRan = true;
                }
            },
        );

        // Runs while the first coroutine is parked in the switch queue.
        $waitGroup->add(
            callback: static function () use ($waitGroup): void {
                $waitGroup->stop();
            },
        );

        $waitGroup->waitAll();

        self::assertTrue($finallyRan);
        self::assertFalse($waitGroup->isLive());

        $this->assertNoTasksCount();
    }

    /**
     * A switch()-parked coroutine and a batch of prefetched results must not
     * starve each other: queued results keep priority, the parked coroutine is
     * resumed once nothing is deliverable, and everything completes.
     */
    public function testSwitchedCoroutineProgressesWithBatchedResults(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): string {
                for ($yieldIndex = 0; $yieldIndex < 5; $yieldIndex++) {
                    Scheduler::get()->switch(quantumMs: 0);
                }

                return 'switcher';
            },
        );

        foreach (range(1, 3) as $sleeperIndex) {
            $waitGroup->add(
                callback: static function () use ($sleeperIndex): string {
                    Sleeper::usleep(microseconds: 1);

                    return 'sleeper-' . $sleeperIndex;
                },
            );
        }

        // Let the sleeper results pile up so the first crossing drains them as
        // one batch while the switcher sits parked in the queue.
        usleep(50_000);

        $results = array_values($waitGroup->waitResults());

        sort($results);

        self::assertSame(
            [
                'sleeper-1',
                'sleeper-2',
                'sleeper-3',
                'switcher',
            ],
            $results,
        );

        $this->assertNoTasksCount();
    }
}
