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
    private Sleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleeper = new Sleeper();
    }

    public function testMulti(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function () use (&$events) {
                $events[] = '1:start';

                $this->sleeper->usleep(milliseconds: 10);

                $events[] = '1:woke';

                $this->sleeper->usleep(milliseconds: 30);

                $events[] = '1:finish';
            },
            function () use (&$events) {
                $events[] = '2:start';

                $this->sleeper->usleep(milliseconds: 20);

                // internal flow
                $callbacks = [
                    function () use (&$events) {
                        $events[] = '2.1:start';

                        $this->sleeper->usleep(milliseconds: 10);

                        $events[] = '2.1:woke';

                        $this->sleeper->usleep(milliseconds: 30);

                        $events[] = '2.1:finish';
                    },
                    function () use (&$events) {
                        $events[] = '2.2:start';

                        $this->sleeper->usleep(milliseconds: 20);

                        $events[] = '2.2:woke';

                        $this->sleeper->usleep(milliseconds: 40);

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
                    $resultsCount
                );
                // internal flow ^

                $events[] = '2:woke';

                $this->sleeper->usleep(milliseconds: 40);

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
            $results
        );

        self::assertSame(
            [
                '1:start',
                '2:start',
                '1:woke',
                // internal flow
                '2.1:start',
                '2.2:start',
                '2.1:woke',
                '2.2:woke',
                '2.1:finish',
                '2.2:finish',
                // internal flow ^
                '2:woke',
                '1:finish',
                '2:finish',
            ],
            $events
        );
    }

    public function testOrder(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function () use (&$events) {
                $this->sleeper->usleep(milliseconds: 10);

                $events[] = '1:finish';

                return '1';
            },
            function () use (&$events) {
                $this->sleeper->usleep(milliseconds: 1);

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
            $events
        );

        self::assertSame(
            [
                '2',
                '1',
            ],
            array_values($results)
        );
    }

    public function testBreak(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function () use (&$events) {
                $this->sleeper->sleep(seconds: 2);

                $events[] = '1:finish';

                return '1';
            },
            function () use (&$events) {
                $this->sleeper->usleep(milliseconds: 1);

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
            $results
        );

        self::assertSame(
            [
                '2',
            ],
            array_values($results)
        );
    }

    public function testSyncAsyncMix(): void
    {
        $callbacks = [
            function () {
                $this->sleeper->usleep(milliseconds: 1);
            },
            function () {
                $this->sleeper->usleep(milliseconds: 1);
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

            $this->sleeper->usleep(milliseconds: 1);
        }

        self::assertCount(
            $callbacksCount,
            $results
        );
    }

    public function testWaitAll(): void
    {
        $callbacks = [
            function () {
                $this->sleeper->usleep(milliseconds: 1);
            },
            function () {
                $this->sleeper->usleep(milliseconds: 1);
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
            $resultsCount
        );
    }

    public function testException(): void
    {
        $exceptionMessage = uniqid();

        $callbacks = [
            function () {
                $this->sleeper->sleep(seconds: 1);
            },
            function () use ($exceptionMessage) {
                $this->sleeper->usleep(milliseconds: 1);

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
            is_null($exception)
        );

        self::assertEquals(
            $exceptionMessage,
            $exception->getMessage()
        );

        self::assertCount(
            0,
            $results
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
                $this->sleeper->usleep(milliseconds: 1);

                $events['first'] = true;
            },
            function () use (&$events, &$exceptionMessage) {
                try {
                    $this->sleeper->usleep(milliseconds: -1);
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
            $exceptionMessage
        );

        self::assertEquals(
            2,
            $resultsCount
        );

        self::assertSame(
            [
                'first'  => true,
                'second' => true,
            ],
            $events
        );
    }

    public function testExtError(): void
    {
        $exceptionMessage = uniqid();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: function () use (&$exception, $exceptionMessage) {
            try {
                $this->sleeper->usleep(milliseconds: -1);
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
            is_null($exception)
        );

        self::assertEquals(
            $exceptionMessage,
            $exception->getMessage()
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

            $this->sleeper->usleep(milliseconds: 1);

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
            $iterationCounter
        );

        self::assertSame(
            [
                'start'  => $iterationCount,
                'finish' => $iterationCount,
            ],
            $events
        );
    }
}
