<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use Closure;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * A flow stopped from inside a coroutine is released on the extension side, and
 * its in-flight tasks are cancelled with it. tearDown's assertNoTasksCount is
 * where that is actually asserted: a crossing that never happened leaves live
 * tasks behind on the other side.
 *
 * These cases were written for the deferred-dispatch queue — State::deleteFlow
 * used to hand the stopFlow to the scheduler instead of crossing from a fiber
 * stack, and the risk was a queue that never drained. The queue is gone and the
 * crossing is immediate, which is why the cases are worth more now than they
 * were then: they assert the outcome, not the mechanism, so they carried over to
 * the mechanism's removal unchanged.
 */
class SchedulerNestedFlowReleaseTest extends BaseTestCase
{
    /**
     * The nested group is abandoned mid-flight: iterate() is left after the first
     * result, so its finally → stop() runs inside the outer coroutine with a
     * member still running — a flow released from a coroutine's own stack.
     */
    public function testANestedGroupStoppedInsideACoroutineReleasesItsFlow(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): string {
                $nested = WaitGroup::create();

                $nested->add(
                    callback: static function (): string {
                        Sleeper::usleep(microseconds: 1_000);

                        return 'fast';
                    },
                );

                $nested->add(
                    callback: static function (): string {
                        Sleeper::sleep(seconds: 30);

                        return 'never';
                    },
                );

                foreach ($nested->iterate() as $value) {
                    // Leaving the loop unwinds the still-running second member and
                    // stops the nested flow from inside this coroutine.
                    return 'nested:' . $value;
                }

                return 'nothing';
            },
        );

        self::assertSame(['nested:fast'], array_values($waitGroup->waitResults()));
    }

    /**
     * The same path for a spawned coroutine: its own flow is deleted by the
     * scheduler while the coroutine's fiber is what finished, and that crossing
     * must land too.
     */
    public function testSpawnedCoroutineFlowsAreReleasedAfterNestedSpawns(): void
    {
        $finished = 0;

        for ($index = 0; $index < 8; ++$index) {
            $this->driveScheduler(static function () use (&$finished): void {
                Sleeper::usleep(microseconds: 500);

                ++$finished;
            });
        }

        self::assertSame(8, $finished);
    }

    /**
     * Runs $callback in a spawned coroutine nested inside a group member, so the
     * spawn and its dispatch happen on a fiber stack, and the group keeps the
     * scheduler pumping until everything settles.
     *
     * @param Closure(): void $callback
     */
    protected function driveScheduler(Closure $callback): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function () use ($callback): string {
                Scheduler::get()->spawn($callback);

                // Outlive the spawned coroutine so the scheduler is still running
                // when it finishes and its flow is released.
                Sleeper::usleep(microseconds: 20_000);

                return 'done';
            },
        );

        $waitGroup->waitAll();
    }
}
