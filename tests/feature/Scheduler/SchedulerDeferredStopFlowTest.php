<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use Closure;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * A cgo call made from a fiber stack costs the Go runtime a system-stack bounds
 * re-derivation through /proc/self/maps, so State::deleteFlow does not cross into
 * Go when it runs inside a coroutine: it queues the stopFlow
 * (Scheduler::deferStopFlow) and the scheduler performs it on its own stack
 * before the next wait.
 *
 * What must not change because of that: a flow stopped from inside a coroutine is
 * still released on the Go side, and its in-flight tasks are still cancelled.
 * tearDown's assertNoTasksCount is the assertion that the deferred crossings
 * actually happened — a queue that never drained leaves live Go tasks behind.
 */
class SchedulerDeferredStopFlowTest extends BaseTestCase
{
    /**
     * The nested group is abandoned mid-flight: iterate() is left after the first
     * result, so its finally → stop() runs inside the outer coroutine with a
     * member still running. That stop is the deferred one.
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
     * scheduler while the coroutine's fiber is what finished, so the crossing is
     * deferred exactly like above and must still land.
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
     * spawn (and its dispatch) happen on a fiber stack — the queued path — and
     * the group keeps the scheduler pumping until everything settles.
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
                // when its deferred dispatch and stopFlow are drained.
                Sleeper::usleep(microseconds: 20_000);

                return 'done';
            },
        );

        $waitGroup->waitAll();
    }
}
