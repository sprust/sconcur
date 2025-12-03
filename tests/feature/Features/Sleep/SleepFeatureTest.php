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

    public function testSleep()
    {
        /** @var string[] $timeline */
        $events = [];

        $callbacks = [
            function (Context $context) use (&$events) {
                $events[] = '1:start';

                SleepFeature::usleep(context: $context, milliseconds: 100);

                $events[] = '1:woke';

                SleepFeature::usleep(context: $context, milliseconds: 100);

                $events[] = '1:woke_2';
            },
            function (Context $context) use (&$events) {
                $events[] = '2:start';

                SleepFeature::usleep(context: $context, milliseconds: 200);

                $events[] = '2:woke';

                SleepFeature::usleep(context: $context, milliseconds: 300);

                $events[] = '2:woke_2';
            },
        ];

        $results = SConcur::run(callbacks: $callbacks, timeoutSeconds: 1);

        $result = SConcur::wait($results);

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
}
