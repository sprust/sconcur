<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use Fiber;
use SConcur\Context\Context;
use SConcur\Exceptions\CallbackExecutionException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\FiberPool;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

class SchedulerFiberPoolTest extends BaseTestCase
{
    public function testSequentialSpawnsReuseOneFiber(): void
    {
        $fiberIds = [];

        $handler = static function () use (&$fiberIds): void {
            $fiberIds[] = self::currentFiberId();
        };

        Scheduler::get()->spawn($handler);
        Scheduler::get()->spawn($handler);
        Scheduler::get()->spawn($handler);

        self::assertCount(3, $fiberIds);
        self::assertNotContains(0, $fiberIds);
        self::assertCount(1, array_unique($fiberIds));
    }

    public function testAsyncSpawnsReuseOneFiber(): void
    {
        $fiberIds = [];

        $handler = static function () use (&$fiberIds): void {
            Sleeper::usleep(microseconds: 1);

            $fiberIds[] = self::currentFiberId();
        };

        Scheduler::get()->spawn($handler);

        $this->pumpScheduler();

        Scheduler::get()->spawn($handler);

        $this->pumpScheduler();

        self::assertCount(2, $fiberIds);
        self::assertNotContains(0, $fiberIds);
        self::assertSame($fiberIds[0], $fiberIds[1]);
    }

    public function testCoroutineContextIsIsolatedBetweenReuses(): void
    {
        $firstFiberId  = 0;
        $secondFiberId = 0;
        $leakedValue   = 'unset';

        Scheduler::get()->spawn(static function () use (&$firstFiberId): void {
            $firstFiberId = self::currentFiberId();

            Context::current()->set(
                key: 'fiber-pool-test-key',
                value: 'first-request',
            );
        });

        Scheduler::get()->spawn(static function () use (&$secondFiberId, &$leakedValue): void {
            $secondFiberId = self::currentFiberId();

            $leakedValue = Context::current()->find('fiber-pool-test-key');
        });

        // The isolation claim is only meaningful when the fiber was reused.
        self::assertSame($firstFiberId, $secondFiberId);
        self::assertNull($leakedValue);
    }

    public function testHandlerFailureKeepsThePoolServing(): void
    {
        $fiberIds = [];

        Scheduler::get()->spawn(static function () use (&$fiberIds): void {
            $fiberIds[] = self::currentFiberId();

            throw new CallbackExecutionException(message: 'handler failure');
        });

        Scheduler::get()->spawn(static function () use (&$fiberIds): void {
            $fiberIds[] = self::currentFiberId();
        });

        // The throw is contained by the worker loop: the fiber survives it and
        // serves the next spawn instead of silently draining the pool.
        self::assertCount(2, $fiberIds);
        self::assertSame($fiberIds[0], $fiberIds[1]);
    }

    public function testNestedSpawnGetsAnotherFiber(): void
    {
        $outerFiberId = 0;
        $innerFiberId = 0;

        Scheduler::get()->spawn(static function () use (&$outerFiberId, &$innerFiberId): void {
            $outerFiberId = self::currentFiberId();

            Scheduler::get()->spawn(static function () use (&$innerFiberId): void {
                $innerFiberId = self::currentFiberId();
            });
        });

        self::assertNotSame(0, $outerFiberId);
        self::assertNotSame(0, $innerFiberId);
        self::assertNotSame($outerFiberId, $innerFiberId);
    }

    public function testReleaseOverMaxIdleEvictsTheFiber(): void
    {
        $fiberPool = new FiberPool(maxIdle: 1);

        $firstFiber  = $fiberPool->acquire();
        $secondFiber = $fiberPool->acquire();

        self::assertNotSame($firstFiber, $secondFiber);

        $fiberPool->release($firstFiber);
        $fiberPool->release($secondFiber);

        // The first release parked within the cap; the second was evicted: its
        // worker loop returned and the fiber terminated.
        self::assertTrue($firstFiber->isSuspended());
        self::assertTrue($secondFiber->isTerminated());
        self::assertSame(1, $fiberPool->idleCount());

        self::assertSame($firstFiber, $fiberPool->acquire());
    }

    public function testShutdownRecyclesTheSpawnedFiber(): void
    {
        $finallyRan    = false;
        $firstFiberId  = 0;
        $secondFiberId = 0;
        $secondResult  = 'unset';

        Scheduler::get()->spawn(static function () use (&$finallyRan, &$firstFiberId): void {
            $firstFiberId = self::currentFiberId();

            try {
                Sleeper::sleep(seconds: 30);
            } finally {
                $finallyRan = true;
            }
        });

        Scheduler::get()->shutdown();

        self::assertTrue($finallyRan);

        // The unwound fiber parked idle and went back to the pool: the next
        // spawn reuses it, and its request completes normally — a late result
        // of the cancelled sleeper cannot resume it (awaited-keys mismatch).
        Scheduler::get()->spawn(static function () use (&$secondFiberId, &$secondResult): void {
            $secondFiberId = self::currentFiberId();

            Sleeper::usleep(microseconds: 1);

            $secondResult = 'done';
        });

        $this->pumpScheduler();

        self::assertSame($firstFiberId, $secondFiberId);
        self::assertSame('done', $secondResult);

        $this->assertNoTasksCount();
    }

    protected static function currentFiberId(): int
    {
        $currentFiber = Fiber::getCurrent();

        return $currentFiber === null ? 0 : spl_object_id($currentFiber);
    }

    /**
     * Drives the scheduler loop long enough to deliver the spawned handlers'
     * results: a group with one longer sleeper keeps Scheduler::run() pumping
     * waitAny, which resumes every live coroutine — spawned ones included.
     */
    protected function pumpScheduler(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): void {
                Sleeper::usleep(microseconds: 20_000);
            },
        );

        $waitGroup->waitAll();
    }
}
