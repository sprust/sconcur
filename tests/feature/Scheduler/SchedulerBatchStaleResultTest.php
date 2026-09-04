<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use SConcur\Connection\Extension;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

class SchedulerBatchStaleResultTest extends BaseTestCase
{
    /**
     * Results cross the boundary in batches, so handling an earlier result may
     * stop the flow of a result already queued on the PHP side. Such a stale
     * result must be dropped silently — not resume anything, not throw — and
     * the scheduler must stay fully usable afterwards.
     */
    public function testStopDropsAStaleQueuedBatchResultSilently(): void
    {
        // The dropped counter is process-global and this suite shares one
        // process: a late result left by an earlier test can land inside this
        // test's window and break the exact +1 assertion. Drain such strays
        // before starting the scenario.
        while (Extension::get()->waitAnyTimeoutBatch(timeoutMs: 50, maxResults: 64) !== null) {
        }

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): string {
                Sleeper::usleep(microseconds: 1);

                return 'first';
            },
        );

        $waitGroup->add(
            callback: static function (): string {
                Sleeper::usleep(microseconds: 1);

                return 'second';
            },
        );

        // Let both results land in the extension-side channel, so the scheduler's
        // first crossing drains them into one batch: consuming the first one
        // leaves the second queued in PHP when stop() kills its flow.
        usleep(50_000);

        $droppedBefore = Scheduler::get()->droppedResultsCount();

        $consumedCount = 0;

        foreach ($waitGroup->iterate() as $unusedResult) {
            ++$consumedCount;

            $waitGroup->stop();

            break;
        }

        self::assertSame(1, $consumedCount);
        self::assertFalse($waitGroup->isLive());

        // The stale queued result must be dropped, not misrouted: a fresh group
        // runs through the same scheduler and completes as if nothing was left
        // behind.
        $freshGroup = WaitGroup::create();

        $freshGroup->add(
            callback: static function (): string {
                Sleeper::usleep(microseconds: 1);

                return 'fresh';
            },
        );

        self::assertSame(['fresh'], array_values($freshGroup->waitResults()));

        // The stale result went through the counted drop — the diagnostic
        // counter is the only trace such a drop leaves. A lower bound, not an
        // exact match: the counter is process-global and a late result of an
        // earlier test in this shared process may land inside this test's
        // window (the drain above narrows that race but cannot close it).
        self::assertGreaterThanOrEqual(
            $droppedBefore + 1,
            Scheduler::get()->droppedResultsCount(),
        );

        $this->assertNoTasksCount();
    }

    /**
     * shutdown() with a batch remainder still queued: the queue is discarded
     * with the rest of the scheduler state, and the scheduler stays fully
     * usable afterwards.
     */
    public function testShutdownDiscardsQueuedBatchResults(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): string {
                Sleeper::usleep(microseconds: 1);

                return 'first';
            },
        );

        $waitGroup->add(
            callback: static function (): string {
                Sleeper::usleep(microseconds: 1);

                return 'second';
            },
        );

        // Both results into one batch; consume one, leave the other queued.
        usleep(50_000);

        foreach ($waitGroup->iterate() as $unusedResult) {
            break;
        }

        Scheduler::get()->shutdown();

        self::assertFalse($waitGroup->isLive());

        $freshGroup = WaitGroup::create();

        $freshGroup->add(
            callback: static function (): string {
                Sleeper::usleep(microseconds: 1);

                return 'fresh';
            },
        );

        self::assertSame(['fresh'], array_values($freshGroup->waitResults()));

        $this->assertNoTasksCount();
    }
}
