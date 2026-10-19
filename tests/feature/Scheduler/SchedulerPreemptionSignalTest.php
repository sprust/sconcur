<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;
use Throwable;

/**
 * Preemption must not reach into a pcntl signal handler. The engine forbids a
 * fiber switch there, so a preemption landing inside one used to throw the
 * handler's FiberError out of it before it finished. The signal was off the
 * queue by then: a server's SIGTERM handler cut short this way lost the stop,
 * and the worker went on serving as if it had never been asked to exit.
 */
class SchedulerPreemptionSignalTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_async_signals') || !function_exists('posix_kill')) {
            self::markTestSkipped('needs ext-pcntl and ext-posix');
        }
    }

    public function testSignalHandlerRunsToTheEndWhilePreemptionIsArmed(): void
    {
        // Written by reference from the closures below.
        /** @var bool $handlerFinished */
        $handlerFinished = false;
        /** @var string|null $handlerError */
        $handlerError = null;
        /** @var string|null $coroutineError */
        $coroutineError = null;

        $previousAsync   = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGUSR1);

        pcntl_signal(
            SIGUSR1,
            static function () use (&$handlerFinished, &$handlerError): void {
                try {
                    // Long enough for several 1 ms quanta to elapse inside the handler.
                    $untilNs = hrtime(true) + 20_000_000;

                    while (hrtime(true) < $untilNs) {
                        // busy
                    }

                    $handlerFinished = true;
                } catch (Throwable $exception) {
                    $handlerError = $exception::class . ': ' . $exception->getMessage();

                    throw $exception;
                }
            },
        );

        Scheduler::get()->enablePreemption(quantumMs: 1);

        try {
            $waitGroup = WaitGroup::create();

            $waitGroup->add(
                callback: static function () use (&$coroutineError): void {
                    try {
                        posix_kill(getmypid(), SIGUSR1);

                        $untilNs = hrtime(true) + 60_000_000;

                        while (hrtime(true) < $untilNs) {
                            // busy, so the signal is dispatched inside this coroutine
                        }
                    } catch (Throwable $exception) {
                        $coroutineError = $exception::class . ': ' . $exception->getMessage();
                    }
                },
            );

            $waitGroup->waitAll();
        } finally {
            Scheduler::get()->disablePreemption();

            pcntl_signal(SIGUSR1, $previousHandler);
            pcntl_async_signals($previousAsync);
        }

        self::assertNull($handlerError, 'The signal handler must not be cut short by a preemption.');
        self::assertTrue($handlerFinished, 'The signal handler must run to its end.');
        self::assertNull($coroutineError, 'No preemption failure may leak into the coroutine.');

        $this->assertNoTasksCount();
    }
}
