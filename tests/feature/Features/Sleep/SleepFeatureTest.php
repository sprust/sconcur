<?php

namespace SConcur\Tests\Feature\Features\Sleep;

use SConcur\Entities\Context;
use SConcur\Features\Sleep\SleepFeature;
use PHPUnit\Framework\TestCase;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;

class SleepFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestContainer::flush();
        TestContainer::resolve();
    }

    public function testMulti(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function (Context $context) use (&$events) {
                $events[] = '1:start';

                SleepFeature::usleep(context: $context, milliseconds: 10);

                $events[] = '1:woke';

                SleepFeature::usleep(context: $context, milliseconds: 10);

                $events[] = '1:woke_2';
            },
            function (Context $context) use (&$events) {
                $events[] = '2:start';

                SleepFeature::usleep(context: $context, milliseconds: 20);

                $events[] = '2:woke';

                SleepFeature::usleep(context: $context, milliseconds: 20);

                $events[] = '2:woke_2';
            },
        ];


        $result = SConcur::waitAll(callbacks: $callbacks, timeoutSeconds: 1);

        self::assertCount(
            2,
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
                SleepFeature::usleep(context: $context, milliseconds: 10);

                $events[] = '1:finish';

                return '1';
            },
            function (Context $context) use (&$events) {
                SleepFeature::usleep(context: $context, milliseconds: 1);

                $events[] = '2:finish';

                return '2';
            },
        ];


        $result = SConcur::waitAll(callbacks: $callbacks, timeoutSeconds: 1);

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
}
