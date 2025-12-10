<?php

namespace SConcur\Tests\Feature\Features\Sleep;

use Exception;
use PHPUnit\Framework\TestCase;
use SConcur\Connection\Extension;
use SConcur\Entities\Context;
use SConcur\Features\Sleep\SleepFeature;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;

class SleepFeatureTest extends TestCase
{
    private Extension $extension;
    private SleepFeature $sleepFeature;

    protected function setUp(): void
    {
        parent::setUp();

        TestContainer::flush();
        TestContainer::resolve();

        $this->extension = new Extension();
        $this->extension->stop();

        self::assertFalse(SConcur::isAsync());

        $this->sleepFeature = SConcur::features()->sleep();
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

                $this->sleepFeature->usleep(context: $context, milliseconds: 20);

                $events[] = '1:woke_2';
            },
            function (Context $context) use (&$events) {
                $events[] = '2:start';

                $this->sleepFeature->usleep(context: $context, milliseconds: 30);

                $events[] = '2:woke';

                $this->sleepFeature->usleep(context: $context, milliseconds: 40);

                $events[] = '2:woke_2';
            },
        ];

        $results = SConcur::run(callbacks: $callbacks, timeoutSeconds: 1);

        $result = [];

        $callbacksCount = count($callbacks);

        foreach ($results as $key => $value) {
            $result[$key] = $value;
            self::assertTrue(SConcur::isAsync());
        }

        // for generator finalization
        unset($results);
        self::assertFalse(SConcur::isAsync());

        self::assertCount(
            $callbacksCount,
            $result
        );

        self::assertSame(
            [
                '1:start',
                '2:start',
                '1:woke',
                '2:woke',
                '1:woke_2',
                '2:woke_2',
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

        $result = SConcur::waitAll(callbacks: $callbacks, timeoutSeconds: 1);

        self::assertEquals(
            0,
            $this->extension->count()
        );

        self::assertFalse(SConcur::isAsync());

        self::assertSame(
            [
                '2:finish',
                '1:finish',
            ],
            $events
        );

        self::assertSame(
            [
                0 => '1',
                1 => '2',
            ],
            $result
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

        $results = SConcur::run(callbacks: $callbacks, timeoutSeconds: 1);

        $result = [];

        foreach ($results as $key => $value) {
            $result[$key] = $value;
            self::assertTrue(SConcur::isAsync());

            break;
        }

        // for generator finalization
        unset($results);
        self::assertFalse(SConcur::isAsync());

        self::assertCount(
            1,
            $result
        );

        self::assertSame(
            [
                1 => '2',
            ],
            $result
        );

        self::assertEquals(
            0,
            $this->extension->count()
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

        $results = SConcur::run(callbacks: $callbacks, timeoutSeconds: 1);

        $result = [];

        $exception = null;

        try {
            foreach ($results as $key => $value) {
                $result[$key] = $value;
                self::assertTrue(SConcur::isAsync());
            }
        } catch (Exception $exception) {
            //
        }

        self::assertFalse(
            is_null($exception)
        );

        // for generator finalization
        unset($results);
        self::assertFalse(SConcur::isAsync());

        self::assertCount(
            0,
            $result
        );

        self::assertEquals(
            0,
            $this->extension->count()
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

        $results = SConcur::run(callbacks: $callbacks, timeoutSeconds: 1);

        $result = [];

        $context = Context::create(timeoutSeconds: 1);

        foreach ($results as $key => $value) {
            $result[$key] = $value;

            $this->sleepFeature->usleep(context: $context, milliseconds: 1);
        }

        // for generator finalization
        unset($results);
        self::assertFalse(SConcur::isAsync());

        self::assertCount(
            $callbacksCount,
            $result
        );

        self::assertEquals(
            0,
            $this->extension->count()
        );
    }
}
