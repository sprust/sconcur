<?php

namespace SConcur\Tests\Feature;

use SConcur\WaitGroup;
use Throwable;

abstract class BaseAsyncTestCase extends BaseTestCase
{
    abstract protected function on_1_start(): void;

    abstract protected function on_1_middle(): void;

    abstract protected function on_2_start(): void;

    abstract protected function on_2_middle(): void;

    abstract protected function on_iterate(): void;

    abstract protected function on_exception(): void;

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
            function () use (&$events) {
                $events[] = '1:start';

                $this->on_1_start();

                $events[] = '1:middle';

                $this->on_1_middle();

                $events[] = '1:last';
            },
            function () use (&$events) {
                $events[] = '2:start';

                $this->on_2_start();

                $events[] = '2:middle';

                $this->on_2_middle();

                $events[] = '2:last';
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

            $this->on_iterate();
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
                $exceptionWaitGroup = WaitGroup::create();

                $exceptionWaitGroup->add(
                    callback: function () {
                        $this->on_exception();
                    }
                );

                try {
                    $exceptionWaitGroup->waitAll();
                } catch (Throwable $exception) {
                    //
                }
            } else {
                try {
                    $this->on_exception();
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
