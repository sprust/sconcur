<?php

namespace SConcur\Tests\Feature\Features\Sleep;

use Exception;
use SConcur\Entities\Context;
use SConcur\Features\Sleep\SleepFeature;
use SConcur\SConcur;
use SConcur\Tests\Feature\Features\BaseTestCase;
use SConcur\WaitGroup;

class SleepTest extends BaseTestCase
{
    private SleepFeature $sleepFeature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleepFeature = SConcur::features()->sleep();
    }

    protected function tearDown(): void
    {
        $this->assertNoTasksCount();

        parent::tearDown();
    }

    public function testMulti(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function (Context $context) use (&$events) {
                $events[] = '1:start';

                $this->sleepFeature->usleep(context: $context, milliseconds: 10);

                $events[] = '1:woke';

                $this->sleepFeature->usleep(context: $context, milliseconds: 30);

                $events[] = '1:finish';
            },
            function (Context $context) use (&$events) {
                $events[] = '2:start';

                $this->sleepFeature->usleep(context: $context, milliseconds: 20);

                $events[] = '2:woke';

                $this->sleepFeature->usleep(context: $context, milliseconds: 40);

                $events[] = '2:finish';
            },
        ];

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->wait();

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
                $this->sleepFeature->usleep(context: $context, milliseconds: 10);

                $events[] = '1:finish';

                return '1';
            },
            function (Context $context) use (&$events) {
                $this->sleepFeature->usleep(context: $context, milliseconds: 1);

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
                $this->sleepFeature->sleep(context: $context, seconds: 2);

                $events[] = '1:finish';

                return '1';
            },
            function (Context $context) use (&$events) {
                $this->sleepFeature->usleep(context: $context, milliseconds: 1);

                $events[] = '2:finish';

                return '2';
            },
        ];

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->wait();

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

    public function testException(): void
    {
        $callbacks = [
            function (Context $context) {
                $this->sleepFeature->sleep(context: $context, seconds: 1);
            },
            function (Context $context) {
                $this->sleepFeature->usleep(context: $context, milliseconds: 1);

                throw new Exception('test');
            },
        ];

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->wait();

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

        self::assertCount(
            0,
            $results
        );
    }

    public function testSyncAsyncMix(): void
    {
        $callbacks = [
            function (Context $context) {
                $this->sleepFeature->usleep(context: $context, milliseconds: 1);
            },
            function (Context $context) {
                $this->sleepFeature->usleep(context: $context, milliseconds: 1);
            },
        ];

        $callbacksCount = count($callbacks);

        $context = Context::create(timeoutSeconds: 1);

        $waitGroup = WaitGroup::create($context);

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->wait();

        $results = [];

        foreach ($generator as $key => $value) {
            $results[$key] = $value;

            $this->sleepFeature->usleep(context: $context, milliseconds: 1);
        }

        self::assertCount(
            $callbacksCount,
            $results
        );
    }
}
