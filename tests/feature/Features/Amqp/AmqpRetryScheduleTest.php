<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\InvalidDelayException;
use SConcur\Exceptions\Amqp\InvalidRetryException;
use SConcur\Exceptions\Amqp\PublishNackedException;
use SConcur\Features\Amqp\RetrySchedule;
use SConcur\Features\Amqp\RetryTopology;
use SConcur\Tests\Feature\BaseTestCase;

/**
 * The two pieces a delayed publish is built out of, tested without a broker: what a schedule
 * answers for an attempt number, and the name both sides of a delayed publish derive.
 */
class AmqpRetryScheduleTest extends BaseTestCase
{
    /**
     * The list is read positionally and does not bound the attempts: it backs off to its
     * last entry and stays there, so a caller who wants a ceiling writes it once at the end
     * rather than repeating it for every retry they allow.
     */
    public function testASchedulePastItsEndKeepsTheLastWait(): void
    {
        $schedule = new RetrySchedule([1, 3, 4]);

        self::assertSame(1.0, $schedule->delaySecondsFor(1));
        self::assertSame(3.0, $schedule->delaySecondsFor(2));
        self::assertSame(4.0, $schedule->delaySecondsFor(3));
        self::assertSame(4.0, $schedule->delaySecondsFor(4));
        self::assertSame(4.0, $schedule->delaySecondsFor(50));
    }

    public function testAnEmptyScheduleWaitsNotAtAll(): void
    {
        $schedule = new RetrySchedule();

        self::assertSame(0.0, $schedule->delaySecondsFor(1));
        self::assertSame(0.0, $schedule->delaySecondsFor(9));
    }

    public function testANegativeWaitIsRefused(): void
    {
        $this->expectException(InvalidRetryException::class);

        new RetrySchedule([1, -2]);
    }

    /** The loop every retried publish runs on: the call is made once more per retry, no more. */
    public function testACallIsRetriedUpToTheNumberAsked(): void
    {
        $schedule = new RetrySchedule();

        $attempts = 0;

        try {
            $schedule->retrying(
                retries: 2,
                retryable: [PublishNackedException::class],
                call: static function () use (&$attempts): void {
                    ++$attempts;

                    throw new PublishNackedException(message: 'refused');
                },
            );

            self::fail('the failure must be raised once the retries are spent');
        } catch (PublishNackedException) {
            // What the caller sees when the schedule runs out.
        }

        self::assertSame(3, $attempts, 'the first attempt plus two retries');
    }

    public function testACallThatSucceedsIsNotRetried(): void
    {
        $schedule = new RetrySchedule();

        $attempts = 0;

        $schedule->retrying(
            retries: 3,
            retryable: [PublishNackedException::class],
            call: static function () use (&$attempts): void {
                ++$attempts;
            },
        );

        self::assertSame(1, $attempts);
    }

    /** Only what was named is retried; anything else is the caller's to see at once. */
    public function testAFailureOutsideTheListIsRaisedOnTheFirstAttempt(): void
    {
        $schedule = new RetrySchedule();

        $attempts = 0;

        try {
            $schedule->retrying(
                retries: 5,
                retryable: [PublishNackedException::class],
                call: static function () use (&$attempts): void {
                    ++$attempts;

                    throw new ChannelException(message: 'the channel is gone');
                },
            );

            self::fail('a failure outside the list must not be retried');
        } catch (ChannelException) {
            // As expected.
        }

        self::assertSame(1, $attempts);
    }

    public function testANegativeNumberOfRetriesIsRefused(): void
    {
        $this->expectException(InvalidRetryException::class);
        $this->expectExceptionMessage('A call cannot be retried a negative number of times, got -1.');

        RetrySchedule::assertRetries(-1);
    }

    public function testTheWaitQueueNameIsDerivedFromTheQueueAndTheDelay(): void
    {
        self::assertSame(
            'orders.wait.5000',
            RetryTopology::waitQueueName(queue: 'orders', delayMs: 5_000),
        );
    }

    public function testADelayOfZeroHasNoWaitQueue(): void
    {
        $this->expectException(InvalidDelayException::class);

        RetryTopology::waitQueueName(queue: 'orders', delayMs: 0);
    }

    public function testTheDefaultExchangeIsNotAQueueToComeBackTo(): void
    {
        $this->expectException(InvalidDelayException::class);

        RetryTopology::waitQueueName(queue: '', delayMs: 1_000);
    }
}
