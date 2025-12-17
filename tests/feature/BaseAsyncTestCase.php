<?php

namespace SConcur\Tests\Feature;

use SConcur\Entities\Context;
use SConcur\WaitGroup;

abstract class BaseAsyncTestCase extends BaseTestCase
{
    abstract protected function on_1_start(Context $context): void;

    abstract protected function on_1_middle(Context $context): void;

    abstract protected function on_2_start(Context $context): void;

    abstract protected function on_2_middle(Context $context): void;

    abstract protected function on_iterate(Context $context): void;

    /**
     * @param array<string, mixed> $results
     */
    abstract protected function assertResult(array $results): void;

    final public function testFlows(): void
    {
        /** @var string[] $events */
        $events = [];

        $callbacks = [
            function (Context $context) use (&$events) {
                $events[] = '1:start';

                $this->on_1_start($context);

                $events[] = '1:middle';

                $this->on_1_middle($context);

                $events[] = '1:last';
            },
            function (Context $context) use (&$events) {
                $events[] = '2:start';

                $this->on_2_start($context);

                $events[] = '2:middle';

                $this->on_2_middle($context);

                $events[] = '2:last';
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

            $this->on_iterate($context);
        }

        self::assertCount(
            count($callbacks),
            $results
        );

        self::assertSame(
            [
                '1:start',
                '2:start',
            ],
            array_slice($events, 0, 2)
        );

        $expectedEvents = [
            '1:start',
            '2:start',
            '1:middle',
            '2:middle',
            '1:last',
            '2:last',
        ];

        foreach ($expectedEvents as $expectedEvent) {
            self::assertContains(
                $expectedEvent,
                $events,
                "Event '$expectedEvent' not found in events: " . implode(', ', $events)
            );
        }

        $this->assertResult($results);
    }
}
