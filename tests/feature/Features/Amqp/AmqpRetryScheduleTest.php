<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\InvalidDelayException;
use SConcur\Exceptions\Amqp\InvalidRetryException;
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
