<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use ReflectionProperty;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Deadline;
use SConcur\Exceptions\InvalidCoroutineTimeoutException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * `WaitGroup::add(timeoutMs: …)`: a coroutine that runs past its deadline is unwound where
 * it stands, and the callback decides what that means for the group.
 */
class SchedulerCoroutineTimeoutTest extends BaseTestCase
{
    public function testACoroutineWaitingOnIoIsUnwoundAtItsDeadline(): void
    {
        $waitGroup = WaitGroup::create();

        $startedAt = microtime(true);

        $waitGroup->add(
            function (): string {
                try {
                    Sleeper::usleep(microseconds: 3_000_000);

                    return 'slept it out';
                } catch (CoroutineTimeoutException) {
                    return 'timed out';
                }
            },
            timeoutMs: 200,
        );

        $results = $waitGroup->waitResults();

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertSame(['timed out'], array_values($results));
        self::assertLessThan(1000, $elapsedMs, "the deadline took {$elapsedMs}ms to fire");

        $this->assertNoTasksCount();
    }

    /**
     * The scheduler blocks in the extension's wait when it has nothing to do. A coroutine
     * waiting on something that never answers is exactly what a deadline is for, so that
     * wait has to end on its own — with nobody else's result to wake it.
     */
    public function testTheDeadlineFiresWithNoOtherWorkToWakeTheScheduler(): void
    {
        $waitGroup = WaitGroup::create();

        $startedAt = microtime(true);

        $waitGroup->add(
            function (): string {
                try {
                    // The only coroutine in the group, and it waits far past its deadline:
                    // no other result will arrive to bring the scheduler back.
                    Sleeper::usleep(microseconds: 5_000_000);

                    return 'slept it out';
                } catch (CoroutineTimeoutException) {
                    return 'timed out';
                }
            },
            timeoutMs: 300,
        );

        self::assertSame(['timed out'], array_values($waitGroup->waitResults()));

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertLessThan(2000, $elapsedMs, "the scheduler slept through the deadline ({$elapsedMs}ms)");

        $this->assertNoTasksCount();
    }

    /**
     * The point of variant A: a timeout caught inside its own callback is that coroutine's
     * business and nobody else's.
     */
    public function testATimeoutCaughtInTheCallbackLeavesTheSiblingsAlone(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            function (): string {
                try {
                    Sleeper::usleep(microseconds: 3_000_000);

                    return 'slow finished';
                } catch (CoroutineTimeoutException) {
                    return 'slow timed out';
                }
            },
            timeoutMs: 150,
        );

        foreach (['first', 'second'] as $name) {
            $waitGroup->add(static function () use ($name): string {
                Sleeper::usleep(microseconds: 400_000);

                return $name;
            });
        }

        $results = array_values($waitGroup->waitResults());

        sort($results);

        self::assertSame(['first', 'second', 'slow timed out'], $results);

        $this->assertNoTasksCount();
    }

    /** A coroutine that finishes in time is never told about the deadline. */
    public function testACoroutineThatFinishesInTimeIsUntouched(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            static function (): string {
                Sleeper::usleep(microseconds: 50_000);

                return 'done';
            },
            timeoutMs: 2_000,
        );

        self::assertSame(['done'], array_values($waitGroup->waitResults()));

        $this->assertNoTasksCount();
    }

    /**
     * A callback that lets the timeout escape fails its group, exactly as any other
     * uncaught exception does. Catching it is what makes it local; not catching it is a
     * decision too.
     */
    public function testAnUncaughtTimeoutFailsTheGroup(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            static function (): string {
                Sleeper::usleep(microseconds: 3_000_000);

                return 'never';
            },
            timeoutMs: 150,
        );

        $this->expectException(CoroutineTimeoutException::class);

        try {
            $waitGroup->waitAll();
        } finally {
            $this->assertNoTasksCount();
        }
    }

    /**
     * It is a FlowStoppedException, so every place that already re-throws a deliberate
     * unwind as-is keeps working without knowing this exception exists.
     */
    public function testTheTimeoutIsAFlowStop(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            static function (): string {
                try {
                    Sleeper::usleep(microseconds: 3_000_000);

                    return 'never';
                } catch (FlowStoppedException $exception) {
                    return $exception::class;
                }
            },
            timeoutMs: 150,
        );

        self::assertSame([CoroutineTimeoutException::class], array_values($waitGroup->waitResults()));

        $this->assertNoTasksCount();
    }

    /**
     * With preemption armed the deadline reaches code that never suspends: the hook runs on
     * the coroutine's own stack, which is the only place a running fiber can be unwound.
     */
    public function testACpuBoundCoroutineIsUnwoundWhenPreemptionIsArmed(): void
    {
        Scheduler::get()->enablePreemption(quantumMs: 1);

        try {
            $waitGroup = WaitGroup::create();

            $waitGroup->add(
                static function (): string {
                    try {
                        $hash = '';

                        // No switch(), no I/O: without preemption nothing could interrupt it.
                        for ($round = 0; $round < 4_000_000; ++$round) {
                            $hash = hash('sha256', $hash);
                        }

                        return 'computed to the end';
                    } catch (CoroutineTimeoutException) {
                        return 'timed out';
                    }
                },
                timeoutMs: 200,
            );

            $startedAt = microtime(true);

            self::assertSame(['timed out'], array_values($waitGroup->waitResults()));

            $elapsedMs = (microtime(true) - $startedAt) * 1000;

            self::assertLessThan(3000, $elapsedMs, "the CPU loop ran on for {$elapsedMs}ms");
        } finally {
            Scheduler::get()->disablePreemption();
        }

        $this->assertNoTasksCount();
    }

    /**
     * The allowance is counted from the moment the callback starts, not from add(): a
     * callback that waited out its turn behind maxConcurrency would otherwise be born
     * already expired.
     */
    public function testTheAllowanceStartsWhenTheCallbackDoes(): void
    {
        $waitGroup = WaitGroup::create(maxConcurrency: 1);

        // Holds the only slot for longer than the second callback's whole allowance.
        $waitGroup->add(static function (): string {
            Sleeper::usleep(microseconds: 400_000);

            return 'first';
        });

        $waitGroup->add(
            static function (): string {
                Sleeper::usleep(microseconds: 50_000);

                return 'second';
            },
            timeoutMs: 300,
        );

        $results = array_values($waitGroup->waitResults());

        sort($results);

        self::assertSame(['first', 'second'], $results);

        $this->assertNoTasksCount();
    }

    /**
     * The scoped form: the deadline is put on the running coroutine, so it works wherever
     * one runs, and it bounds a part of the work instead of the whole callback.
     */
    public function testADeadlineScopeBoundsOnlyWhatItWraps(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function (): string {
            // Outside the scope and deliberately longer than it: unbounded work must stay
            // unbounded.
            Sleeper::usleep(microseconds: 300_000);

            try {
                return Deadline::run(
                    timeoutMs: 150,
                    callback: static function (): string {
                        Sleeper::usleep(microseconds: 3_000_000);

                        return 'inner finished';
                    },
                );
            } catch (CoroutineTimeoutException) {
                return 'inner timed out';
            }
        });

        self::assertSame(['inner timed out'], array_values($waitGroup->waitResults()));

        $this->assertNoTasksCount();
    }

    /** A scope that finishes in time leaves the coroutine unbounded again. */
    public function testAScopeThatFinishesLeavesNoDeadlineBehind(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function (): string {
            Deadline::run(
                timeoutMs: 2_000,
                callback: static function (): void {
                    Sleeper::usleep(microseconds: 20_000);
                },
            );

            // Far longer than the scope that just ended: if its deadline outlived it, this
            // would be unwound.
            Sleeper::usleep(microseconds: 500_000);

            return 'still here';
        });

        self::assertSame(['still here'], array_values($waitGroup->waitResults()));

        $this->assertNoTasksCount();
    }

    /**
     * Nesting: the shorter allowance wins, whichever way round it is asked for. An inner
     * scope cannot buy itself more time than the outer one is holding.
     */
    public function testTheShorterOfTwoNestedScopesWins(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function (): string {
            try {
                return Deadline::run(
                    timeoutMs: 200,
                    callback: static function (): string {
                        // Asks for ten times the allowance it is running inside.
                        return Deadline::run(
                            timeoutMs: 2_000,
                            callback: static function (): string {
                                Sleeper::usleep(microseconds: 3_000_000);

                                return 'inner finished';
                            },
                        );
                    },
                );
            } catch (CoroutineTimeoutException) {
                return 'outer allowance held';
            }
        });

        $startedAt = microtime(true);

        self::assertSame(['outer allowance held'], array_values($waitGroup->waitResults()));

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertLessThan(1500, $elapsedMs, "the inner scope bought more time ({$elapsedMs}ms)");

        $this->assertNoTasksCount();
    }

    /** Outside a coroutine there is nothing to unwind, so the work simply runs. */
    public function testOutsideACoroutineTheCallbackRunsUnbounded(): void
    {
        $result = Deadline::run(
            timeoutMs: 50,
            callback: static function (): string {
                usleep(120_000);

                return 'ran to the end';
            },
        );

        self::assertSame('ran to the end', $result);
    }

    /**
     * A deadline is an entry in a list the scheduler keeps for as long as the coroutine
     * lives, and the scheduler consults that list on every decision it makes. An entry left
     * behind by a coroutine that is gone would be scanned for ever, and — worse — its fiber
     * id is reused once the fiber is freed, so a stale deadline could expire a coroutine
     * that never asked for one.
     *
     * Every way a coroutine can end is exercised here: it finished in time, it timed out,
     * it timed out inside a scope, it left a scope behind, and its group was stopped under
     * it.
     */
    public function testNoDeadlineOutlivesItsCoroutine(): void
    {
        $deadlines = new ReflectionProperty(Scheduler::class, 'deadlines');

        self::assertSame([], $deadlines->getValue(Scheduler::get()), 'the run started dirty');

        // Finished in time, timed out, and a scope inside a coroutine that has its own.
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static fn(): string => 'immediate');

        $waitGroup->add(
            static function (): string {
                Sleeper::usleep(microseconds: 30_000);

                return 'in time';
            },
            timeoutMs: 2_000,
        );

        $waitGroup->add(
            static function (): string {
                try {
                    Sleeper::usleep(microseconds: 3_000_000);

                    return 'never';
                } catch (CoroutineTimeoutException) {
                    return 'timed out';
                }
            },
            timeoutMs: 150,
        );

        $waitGroup->add(static function (): string {
            try {
                return Deadline::run(
                    timeoutMs: 100,
                    callback: static function (): string {
                        Sleeper::usleep(microseconds: 3_000_000);

                        return 'never';
                    },
                );
            } catch (CoroutineTimeoutException) {
                return 'scope timed out';
            }
        });

        $waitGroup->add(static function (): string {
            Deadline::run(
                timeoutMs: 5_000,
                callback: static function (): void {
                    Sleeper::usleep(microseconds: 10_000);
                },
            );

            return 'scope left behind';
        });

        $waitGroup->waitAll();

        self::assertSame([], $deadlines->getValue(Scheduler::get()), 'a settled coroutine left its deadline');

        // Stopped from the outside, both while waiting and while inside a scope.
        $stopped = WaitGroup::create();

        $stopped->add(
            static function (): string {
                Sleeper::usleep(microseconds: 5_000_000);

                return 'never';
            },
            timeoutMs: 4_000,
        );

        $stopped->add(static function (): string {
            return Deadline::run(
                timeoutMs: 4_000,
                callback: static function (): string {
                    Sleeper::usleep(microseconds: 5_000_000);

                    return 'never';
                },
            );
        });

        $stopped->add(static function () use ($stopped): string {
            Sleeper::usleep(microseconds: 100_000);

            $stopped->stop();

            return 'stopper';
        });

        $stopped->waitAll();

        self::assertSame([], $deadlines->getValue(Scheduler::get()), 'a stopped coroutine left its deadline');

        $this->assertNoTasksCount();
    }

    public function testANegativeScopeIsRefused(): void
    {
        $this->expectException(InvalidCoroutineTimeoutException::class);

        Deadline::run(
            timeoutMs: -1,
            callback: static fn(): string => 'never',
        );
    }

    /**
     * A server's handler timeout comes from argv, where a negative one is a typo. It is
     * screened when the loop starts rather than by the spawn of the first request: the Go
     * side clamps its own copy to zero and serves happily, so left to spawn() the process
     * would bind its listener and then raise on every request it accepted.
     */
    public function testAServerRefusesANegativeHandlerTimeout(): void
    {
        $this->expectException(InvalidCoroutineTimeoutException::class);

        Scheduler::get()->serve(
            serverFlowKey: 'flow-never-served',
            serverTaskKey: 'task-never-served',
            maxRequests: 1,
            onRequest: static fn(): null => null,
            shouldStop: static fn(): bool => true,
            onDrainStart: static fn(): null => null,
            onShutdownStep: static fn(): null => null,
            handlerTimeoutMs: -1,
        );
    }

    public function testANegativeTimeoutIsRefused(): void
    {
        $waitGroup = WaitGroup::create();

        $this->expectException(InvalidCoroutineTimeoutException::class);

        $waitGroup->add(static fn(): string => 'never', timeoutMs: -1);
    }

    /**
     * A refused timeout must not leave the group holding a member that was never started:
     * such a member keeps isLive() true, so waitAll() would wait for a coroutine that does
     * not exist.
     */
    public function testARefusedTimeoutLeavesNoPhantomMember(): void
    {
        $waitGroup = WaitGroup::create();

        try {
            $waitGroup->add(static fn(): string => 'never', timeoutMs: -1);

            self::fail('the negative timeout was accepted');
        } catch (InvalidCoroutineTimeoutException) {
            // expected
        }

        self::assertFalse($waitGroup->isLive());
        self::assertSame(0, $waitGroup->waitAll());

        $this->assertNoTasksCount();
    }

    /**
     * Zero is how the whole library says "no deadline", so a callback given one runs to its
     * end instead of being refused or unwound.
     */
    public function testAZeroTimeoutMeansNoDeadline(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            static function (): string {
                Sleeper::usleep(microseconds: 200_000);

                return 'finished';
            },
            timeoutMs: 0,
        );

        self::assertSame(['finished'], array_values($waitGroup->waitResults()));

        $this->assertNoTasksCount();
    }

    /**
     * A scope of zero bounds nothing of its own, and must not lift the bound it runs under
     * either — the outer allowance is still someone's promise.
     */
    public function testAZeroScopeKeepsTheDeadlineItRunsUnder(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            static function (): string {
                try {
                    return Deadline::run(
                        timeoutMs: 0,
                        callback: static function (): string {
                            Sleeper::usleep(microseconds: 3_000_000);

                            return 'slept it out';
                        },
                    );
                } catch (CoroutineTimeoutException) {
                    return 'timed out';
                }
            },
            timeoutMs: 200,
        );

        self::assertSame(['timed out'], array_values($waitGroup->waitResults()));

        $this->assertNoTasksCount();
    }

    /**
     * An inner scope asking for more time than the outer one holds gets the outer instant,
     * so when it fires both are up. Putting the outer one back on the way out would deliver
     * a second CoroutineTimeoutException into the cleanup the first one started — the
     * callback below would never reach its return.
     */
    public function testAnInnerScopeSharingTheOuterInstantTimesOutOnce(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            static function (): string {
                try {
                    Deadline::run(
                        timeoutMs: 5_000,
                        callback: static function (): void {
                            Sleeper::usleep(microseconds: 3_000_000);
                        },
                    );

                    return 'slept it out';
                } catch (CoroutineTimeoutException) {
                    // Cleanup that awaits: it must not be cut by the same deadline again.
                    Sleeper::usleep(microseconds: 300_000);

                    return 'cleaned up';
                }
            },
            timeoutMs: 200,
        );

        self::assertSame(['cleaned up'], array_values($waitGroup->waitResults()));

        $this->assertNoTasksCount();
    }

    /**
     * A nested add() hands its first push to the scheduler's queue instead of crossing into
     * Go from a fiber stack. When the deadline fires before that queue is drained, the
     * queued push must be dropped: sending it would overwrite the keys of whatever the
     * coroutine asked for inside its catch, and the old task's result would wake it in
     * place of the answer it is actually waiting for.
     */
    public function testAQueuedPushIsDroppedWhenTheDeadlineFiresFirst(): void
    {
        $outer = WaitGroup::create();

        $outer->add(static function (): string {
            $inner = WaitGroup::create();

            $inner->add(
                static function (): string {
                    try {
                        // Pushed from the adder's fiber stack, so it waits in the
                        // scheduler's dispatch queue rather than crossing into Go here.
                        Sleeper::usleep(microseconds: 3_000_000);

                        return 'slept it out';
                    } catch (CoroutineTimeoutException) {
                        $startedAt = microtime(true);

                        Sleeper::usleep(microseconds: 300_000);

                        $waitedMs = (int) round((microtime(true) - $startedAt) * 1000);

                        return $waitedMs < 1_500
                            ? 'waited its own wait'
                            : "woke on the abandoned task after {$waitedMs}ms";
                    }
                },
                timeoutMs: 50,
            );

            // Hold the thread past that deadline without suspending: the scheduler's first
            // look then finds it expired while the queued push is still unsent, which is
            // the order the two have to meet in for the stale push to matter.
            $busyUntil = microtime(true) + 0.15;

            while (microtime(true) < $busyUntil) {
            }

            return (string) array_values($inner->waitResults())[0];
        });

        self::assertSame(['waited its own wait'], array_values($outer->waitResults()));

        $this->assertNoTasksCount();
    }

    /**
     * A deadline scope closing on the way out of a stopped coroutine used to write the
     * outer deadline back into the index, where nothing was left to remove it. The index
     * is keyed by fiber id, and PHP hands a freed fiber's id to the next fiber it
     * allocates — so the entry came due on a coroutine that had asked for no deadline at
     * all and unwound it.
     */
    public function testStoppingACoroutineInADeadlineScopeLeavesNothingForTheNextFiber(): void
    {
        $stopped = WaitGroup::create();

        $stopped->add(
            static function (): string {
                return Deadline::run(
                    timeoutMs: 60_000,
                    callback: static function (): string {
                        Sleeper::usleep(microseconds: 3_000_000);

                        return 'slept it out';
                    },
                );
            },
            timeoutMs: 500,
        );

        $stopped->stop();

        $property = new ReflectionProperty(Scheduler::class, 'deadlines');

        self::assertSame(
            [],
            $property->getValue(Scheduler::get()),
            'the stopped coroutine left its deadline behind',
        );

        // The same shape again, so the freed fiber id is the one this member gets — and
        // this one asked for no deadline, so nothing here may be unwound.
        $innocent = WaitGroup::create();

        $innocent->add(static function (): string {
            try {
                Sleeper::usleep(microseconds: 900_000);

                return 'ran to the end';
            } catch (CoroutineTimeoutException) {
                return 'unwound by a deadline it never asked for';
            }
        });

        self::assertSame(['ran to the end'], array_values($innocent->waitResults()));

        $this->assertNoTasksCount();
    }

    /**
     * A member that runs out of its own allowance before it ever suspends fails its group.
     * It used to depend on how it got to its slot: a queued one was routed to the group,
     * one that found a slot free travelled up the adder's stack instead — where it is
     * indistinguishable from the adder's own deadline, and a server handler that lets one
     * through answers nothing, having read it as a deliberate unwind.
     */
    public function testAMembersOwnTimeoutFailsItsGroupRatherThanTheAdder(): void
    {
        // A quantum far longer than the allowance: the deadline has already passed by the
        // time the first interrupt arrives, so the hook unwinds the member where it stands
        // instead of parking it. A member that parks first is expired by the scheduler and
        // never reaches the adder — this is the one ordering that does.
        Scheduler::get()->enablePreemption(quantumMs: 150);

        try {
            $waitGroup = WaitGroup::create();

            // No slot to wait for, so this one is launched from add() itself.
            $callbackKey = $waitGroup->add(
                static function (): string {
                    $hash = '';

                    // Never suspends, and never catches: preemption is the only thing that
                    // can end it, and what it throws is meant to escape.
                    for ($round = 0; $round < 4_000_000; ++$round) {
                        $hash = hash('sha256', $hash);
                    }

                    return 'computed to the end';
                },
                timeoutMs: 10,
            );

            self::assertNotSame(
                '',
                $callbackKey,
                'add() must return rather than raise the member timeout in the adder',
            );

            try {
                $waitGroup->waitResults();

                self::fail('the group must report the member that ran out of time');
            } catch (CoroutineTimeoutException $exception) {
                self::assertStringContainsString('timed out', $exception->getMessage());
            }
        } finally {
            Scheduler::get()->disablePreemption();
        }

        $this->assertNoTasksCount();
    }
}
