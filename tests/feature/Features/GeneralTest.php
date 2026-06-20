<?php

namespace SConcur\Tests\Feature\Features;

use Exception;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;
use Throwable;

class GeneralTest extends BaseTestCase
{
    public function testMulti(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function () use (&$events) {
                $events[] = '1:start';

                Sleeper::usleep(microseconds: 60000);

                $events[] = '1:woke';

                Sleeper::usleep(microseconds: 180000);

                $events[] = '1:finish';
            },
            function () use (&$events) {
                $events[] = '2:start';

                Sleeper::usleep(microseconds: 120000);

                // internal flow
                $callbacks = [
                    function () use (&$events) {
                        $events[] = '2.1:start';

                        Sleeper::usleep(microseconds: 60000);

                        $events[] = '2.1:woke';

                        Sleeper::usleep(microseconds: 180000);

                        $events[] = '2.1:finish';
                    },
                    function () use (&$events) {
                        $events[] = '2.2:start';

                        Sleeper::usleep(microseconds: 120000);

                        $events[] = '2.2:woke';

                        Sleeper::usleep(microseconds: 240000);

                        $events[] = '2.2:finish';
                    },
                ];

                $waitGroup = WaitGroup::create();

                foreach ($callbacks as $callback) {
                    $waitGroup->add(callback: $callback);
                }

                $resultsCount = $waitGroup->waitAll();

                self::assertEquals(
                    count($callbacks),
                    $resultsCount,
                );
                // internal flow ^

                $events[] = '2:woke';

                Sleeper::usleep(microseconds: 240000);

                $events[] = '2:finish';
            },
        ];

        $waitGroup = WaitGroup::create();

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->iterate();

        $results = [];

        foreach ($generator as $key => $value) {
            $results[$key] = $value;
        }

        self::assertCount(
            count($callbacks),
            $results,
        );

        foreach (
            [
                '1:start', '2:start', '1:woke',
                '2.1:start', '2.2:start', '2.1:woke', '2.2:woke',
                '2.1:finish', '2.2:finish', '2:woke', '1:finish', '2:finish',
            ] as $expectedEvent
        ) {
            self::assertContains($expectedEvent, $events);
        }

        $positionOf = static fn(string $event): int => (int) array_search($event, $events, true);

        // Both top-level coroutines start before either makes progress.
        self::assertLessThan($positionOf('1:woke'), $positionOf('1:start'));
        self::assertLessThan($positionOf('2.1:start'), $positionOf('2:start'));

        // Cross-flow concurrency: the outer coroutine 1 finishes WHILE the nested
        // group of coroutine 2 is still running — i.e. before the nested coroutines
        // finish. Under the old blocking model 1:finish only came after the whole
        // nested flow had completed.
        self::assertLessThan($positionOf('2.1:finish'), $positionOf('1:finish'));
        self::assertLessThan($positionOf('2.2:finish'), $positionOf('1:finish'));

        // The nested group completes before coroutine 2 resumes past its waitAll.
        self::assertLessThan($positionOf('2:woke'), $positionOf('2.1:finish'));
        self::assertLessThan($positionOf('2:woke'), $positionOf('2.2:finish'));

        // Coroutine 2 (with the nested flow) finishes after coroutine 1.
        self::assertLessThan($positionOf('2:finish'), $positionOf('1:finish'));
    }

    public function testOrder(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function () use (&$events) {
                // The gap must be wide: when the PHP side stalls longer than the
                // difference, both results are pending and arrive in random order.
                Sleeper::usleep(microseconds: 100000);

                $events[] = '1:finish';

                return '1';
            },
            function () use (&$events) {
                Sleeper::usleep(microseconds: 1000);

                $events[] = '2:finish';

                return '2';
            },
        ];

        $waitGroup = WaitGroup::create();

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $results = $waitGroup->waitResults();

        self::assertSame(
            [
                '2:finish',
                '1:finish',
            ],
            $events,
        );

        self::assertSame(
            [
                '2',
                '1',
            ],
            array_values($results),
        );
    }

    public function testBreak(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function () use (&$events) {
                Sleeper::sleep(seconds: 2);

                $events[] = '1:finish';

                return '1';
            },
            function () use (&$events) {
                Sleeper::usleep(microseconds: 1000);

                $events[] = '2:finish';

                return '2';
            },
        ];

        $waitGroup = WaitGroup::create();

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->iterate();

        $results = [];

        foreach ($generator as $key => $value) {
            $results[$key] = $value;

            break;
        }

        self::assertCount(
            1,
            $results,
        );

        self::assertSame(
            [
                '2',
            ],
            array_values($results),
        );
    }

    public function testBreakUnwindsSuspendedCallback(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            // Stays suspended past the break: its finally must still run.
            function () use (&$events) {
                try {
                    Sleeper::sleep(seconds: 2);

                    $events[] = '1:finish';
                } finally {
                    $events[] = '1:finally';
                }
            },
            function () use (&$events) {
                Sleeper::usleep(microseconds: 1000);

                $events[] = '2:finish';
            },
        ];

        $waitGroup = WaitGroup::create();

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->iterate();

        foreach ($generator as $ignored) {
            break;
        }

        // Dropping the generator runs its finally { stop() }, which unwinds the
        // still-suspended first callback.
        unset($generator);

        self::assertContains('2:finish', $events);
        self::assertContains('1:finally', $events);
        self::assertNotContains('1:finish', $events);
    }

    public function testSyncAsyncMix(): void
    {
        $callbacks = [
            function () {
                Sleeper::usleep(microseconds: 1000);
            },
            function () {
                Sleeper::usleep(microseconds: 1000);
            },
        ];

        $callbacksCount = count($callbacks);

        $waitGroup = WaitGroup::create();

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->iterate();

        $results = [];

        foreach ($generator as $key => $value) {
            $results[$key] = $value;

            Sleeper::usleep(microseconds: 1000);
        }

        self::assertCount(
            $callbacksCount,
            $results,
        );
    }

    public function testWaitAll(): void
    {
        $callbacks = [
            function () {
                Sleeper::usleep(microseconds: 1000);
            },
            function () {
                Sleeper::usleep(microseconds: 1000);
            },
        ];

        $callbacksCount = count($callbacks);

        $waitGroup = WaitGroup::create();

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $resultsCount = $waitGroup->waitAll();

        self::assertEquals(
            $callbacksCount,
            $resultsCount,
        );
    }

    public function testException(): void
    {
        $exceptionMessage = uniqid();

        $callbacks = [
            function () {
                Sleeper::sleep(seconds: 1);
            },
            function () use ($exceptionMessage) {
                Sleeper::usleep(microseconds: 1000);

                throw new Exception($exceptionMessage);
            },
        ];

        $waitGroup = WaitGroup::create();

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->iterate();

        $results = [];

        $exception = null;

        try {
            foreach ($generator as $key => $value) {
                $results[$key] = $value;
            }
        } catch (Exception $exception) {
            //
        }

        self::assertFalse(
            is_null($exception),
        );

        self::assertEquals(
            $exceptionMessage,
            $exception->getMessage(),
        );

        self::assertCount(
            0,
            $results,
        );
    }

    public function testTryCatch(): void
    {
        $events = [
            'first'  => false,
            'second' => false,
        ];

        $exceptionMessage = null;

        $callbacks = [
            function () use (&$events) {
                Sleeper::usleep(microseconds: 1000);

                $events['first'] = true;
            },
            function () use (&$events, &$exceptionMessage) {
                try {
                    Sleeper::usleep(microseconds: -1000);
                } catch (Throwable $exception) {
                    $exceptionMessage = $exception->getMessage();
                }

                $events['second'] = true;
            },
        ];

        $waitGroup = WaitGroup::create();

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $resultsCount = $waitGroup->waitAll();

        self::assertNotNull(
            $exceptionMessage,
        );

        self::assertEquals(
            2,
            $resultsCount,
        );

        self::assertSame(
            [
                'first'  => true,
                'second' => true,
            ],
            $events,
        );
    }

    public function testExtError(): void
    {
        $exceptionMessage = uniqid();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function () use (&$exception, $exceptionMessage) {
            try {
                Sleeper::usleep(microseconds: -1000);
            } catch (TaskErrorException $exception) {
                throw new Exception($exceptionMessage);
            }
        });

        $exception = null;

        try {
            $waitGroup->waitAll();
        } catch (Exception $exception) {
            //
        }

        self::assertFalse(
            is_null($exception),
        );

        self::assertEquals(
            $exceptionMessage,
            $exception->getMessage(),
        );
    }

    public function testAddAtIteration(): void
    {
        $events = [
            'start'  => 0,
            'finish' => 0,
        ];

        $callback = function () use (&$events) {
            ++$events['start'];

            Sleeper::usleep(microseconds: 1000);

            ++$events['finish'];
        };

        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: $callback);

        $iterationCount   = 5;
        $iterationCounter = $iterationCount;

        $generator = $waitGroup->iterate();

        foreach ($generator as $ignored) {
            --$iterationCounter;

            if ($iterationCounter === 0) {
                continue;
            }

            $waitGroup->add(callback: $callback);
        }

        self::assertEquals(
            0,
            $iterationCounter,
        );

        self::assertSame(
            [
                'start'  => $iterationCount,
                'finish' => $iterationCount,
            ],
            $events,
        );
    }

    public function testStop(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function (): string {
            Sleeper::sleep(seconds: 5);

            return 'should-not-complete';
        });

        // stop() cancels the in-flight flow; iterate() must then yield nothing and
        // return immediately instead of waiting for the 5s task.
        $waitGroup->stop();

        $start = microtime(true);

        $results = iterator_to_array($waitGroup->iterate());

        $elapsedSeconds = microtime(true) - $start;

        self::assertCount(0, $results);
        self::assertLessThan(1, $elapsedSeconds);
    }

    public function testNestedGroupRunsConcurrentlyWithOuterFlow(): void
    {
        /** @var string[] $events */
        $events = [];

        $waitGroup = WaitGroup::create();

        // Fast outer coroutine.
        $waitGroup->add(function () use (&$events) {
            Sleeper::usleep(microseconds: 50000);

            $events[] = 'outer:fast';
        });

        // Outer coroutine that spawns a nested group of slow coroutines.
        $waitGroup->add(function () use (&$events) {
            $inner = WaitGroup::create();

            $inner->add(function () use (&$events) {
                Sleeper::usleep(microseconds: 300000);

                $events[] = 'inner:slow';
            });

            $inner->waitAll();

            $events[] = 'outer:slow';
        });

        $waitGroup->waitAll();

        // The fast outer coroutine must finish before the nested slow coroutine:
        // the nested group does not block the outer flow. Under the old blocking
        // model the nested waitAll() froze the outer flow, so 'outer:fast' could
        // only appear after 'inner:slow'.
        self::assertContains('outer:fast', $events);
        self::assertContains('inner:slow', $events);

        self::assertLessThan(
            (int) array_search('inner:slow', $events, true),
            (int) array_search('outer:fast', $events, true),
        );
    }

    public function testNestedIterateRunsConcurrentlyWithOuterFlow(): void
    {
        /** @var string[] $events */
        $events = [];

        /** @var array<string, string> $innerResults */
        $innerResults = [];

        $waitGroup = WaitGroup::create();

        // Fast outer coroutine.
        $waitGroup->add(function () use (&$events) {
            Sleeper::usleep(microseconds: 50000);

            $events[] = 'outer:fast';
        });

        // Outer coroutine that spawns a nested group and drains it through a
        // manually consumed iterate() generator (not waitAll()/waitResults()).
        $waitGroup->add(function () use (&$events, &$innerResults) {
            $inner = WaitGroup::create();

            $inner->add(function () use (&$events): string {
                Sleeper::usleep(microseconds: 300000);

                $events[] = 'inner:slow';

                return 'slow';
            });

            $inner->add(function () use (&$events): string {
                Sleeper::usleep(microseconds: 320000);

                $events[] = 'inner:slower';

                return 'slower';
            });

            foreach ($inner->iterate() as $key => $value) {
                $innerResults[$key] = $value;
            }

            $events[] = 'outer:slow';
        });

        $waitGroup->waitAll();

        // The nested iterate() yields every nested result.
        self::assertEqualsCanonicalizing(['slow', 'slower'], array_values($innerResults));

        // Cross-flow: the fast outer coroutine finishes before the nested slow
        // coroutines, i.e. a nested iterate() does not block the outer flow.
        self::assertContains('outer:fast', $events);
        self::assertContains('inner:slow', $events);

        self::assertLessThan(
            (int) array_search('inner:slow', $events, true),
            (int) array_search('outer:fast', $events, true),
        );

        // The coroutine driving the nested iterate() only proceeds once its
        // nested group has fully drained.
        self::assertLessThan(
            (int) array_search('outer:slow', $events, true),
            (int) array_search('inner:slower', $events, true),
        );
    }

    public function testSiblingWaitGroupsBothComplete(): void
    {
        $first  = WaitGroup::create();
        $second = WaitGroup::create();

        $first->add(function (): string {
            Sleeper::usleep(microseconds: 20000);

            return 'first';
        });

        $second->add(function (): string {
            Sleeper::usleep(microseconds: 20000);

            return 'second';
        });

        // Two independent groups on the top level must both resolve correctly
        // without deadlock or cross-routing of results.
        self::assertSame(['first'], array_values($first->waitResults()));
        self::assertSame(['second'], array_values($second->waitResults()));
    }
}
