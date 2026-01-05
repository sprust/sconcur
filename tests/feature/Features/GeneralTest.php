<?php

namespace SConcur\Tests\Feature\Features;

use Exception;
use SConcur\Entities\Context;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

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
            function (Context $context) use (&$events) {
                $events[] = '1:start';

                $this->sleeper->usleep(context: $context, milliseconds: 10);

                $events[] = '1:woke';

                $this->sleeper->usleep(context: $context, milliseconds: 30);

                $events[] = '1:finish';
            },
            function (Context $context) use (&$events) {
                $events[] = '2:start';

                $this->sleeper->usleep(context: $context, milliseconds: 20);

                $events[] = '2:woke';

                $this->sleeper->usleep(context: $context, milliseconds: 40);

                $events[] = '2:finish';
            },
        ];

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

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
            function (Context $context) use (&$events) {
                $this->sleeper->usleep(context: $context, milliseconds: 10);

                $events[] = '1:finish';

                return '1';
            },
            function (Context $context) use (&$events) {
                $this->sleeper->usleep(context: $context, milliseconds: 1);

                $events[] = '2:finish';

                return '2';
            },
        ];

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

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
            function (Context $context) use (&$events) {
                $this->sleeper->sleep(context: $context, seconds: 2);

                $events[] = '1:finish';

                return '1';
            },
            function (Context $context) use (&$events) {
                $this->sleeper->usleep(context: $context, milliseconds: 1);

                $events[] = '2:finish';

                return '2';
            },
        ];

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

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
            function (Context $context) {
                $this->sleeper->usleep(context: $context, milliseconds: 1);
            },
            function (Context $context) {
                $this->sleeper->usleep(context: $context, milliseconds: 1);
            },
        ];

        $callbacksCount = count($callbacks);

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->iterate();

        $results = [];

        foreach ($generator as $key => $value) {
            $results[$key] = $value;

            $this->sleeper->usleep(context: $context, milliseconds: 1);
        }

        self::assertCount(
            $callbacksCount,
            $results
        );
    }

    public function testWaitAll(): void
    {
        $callbacks = [
            function (Context $context) {
                $this->sleeper->usleep(context: $context, milliseconds: 1);
            },
            function (Context $context) {
                $this->sleeper->usleep(context: $context, milliseconds: 1);
            },
        ];

        $callbacksCount = count($callbacks);

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

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
            function (Context $context) {
                $this->sleeper->sleep(context: $context, seconds: 1);
            },
            function (Context $context) use ($exceptionMessage) {
                $this->sleeper->usleep(context: $context, milliseconds: 1);

                throw new Exception($exceptionMessage);
            },
        ];

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

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

    public function testExtError(): void
    {
        $exceptionMessage = uniqid();

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

        $waitGroup->add(callback: function (Context $context) use (&$exception, $exceptionMessage) {
            try {
                $this->sleeper->usleep(context: $context, milliseconds: -1);
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
}
