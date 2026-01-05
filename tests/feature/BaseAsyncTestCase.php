<?php

namespace SConcur\Tests\Feature;

use SConcur\Entities\Context;
use SConcur\WaitGroup;
use Throwable;

abstract class BaseAsyncTestCase extends BaseTestCase
{
    abstract protected function on_1_start(Context $context): void;

    abstract protected function on_1_middle(Context $context): void;

    abstract protected function on_2_start(Context $context): void;

    abstract protected function on_2_middle(Context $context): void;

    abstract protected function on_iterate(Context $context): void;

    abstract protected function on_exception(Context $context): void;

    abstract protected function assertException(Throwable $exception): void;

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

        $context = Context::create(timeoutSeconds: 2);

        $waitGroup = WaitGroup::create($context);

        foreach ($callbacks as $callback) {
            $waitGroup->add(callback: $callback);
        }

        $generator = $waitGroup->iterate();

        $results = [];

        foreach ($generator as $key => $value) {
            $results[$key] = $value;

            $this->on_iterate($context);
        }

        self::assertCount(
            count($callbacks),
            $results
        );

        $expectedStartEvents = [
            '1:start',
            '2:start',
        ];

        self::assertSame(
            $expectedStartEvents,
            array_slice($events, 0, count($expectedStartEvents))
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

        foreach ([true, false] as $isAsync) {
            $exception = null;

            if ($isAsync) {
                $exceptionWaitGroup = WaitGroup::create($context);

                $exceptionWaitGroup->add(
                    callback: function (Context $context) {
                        $this->on_exception($context);
                    }
                );

                try {
                    $exceptionWaitGroup->waitAll();
                } catch (Throwable $exception) {
                    //
                }
            } else {
                try {
                    $this->on_exception($context);
                } catch (Throwable $exception) {
                    //
                }
            }

            self::assertFalse(
                is_null($exception),
                'Exception not fired for ' . ($isAsync ? 'async' : 'async') . ' case'
            );

            $this->assertException($exception);
        }

        $this->assertResult($results);
    }
}
