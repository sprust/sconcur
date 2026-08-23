<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\InvalidDelayException;

/**
 * The wait queues behind `Queue::publish(delayMs: …)`.
 *
 * AMQP has no delayed publish. What it has is a queue nobody consumes whose messages expire
 * into another queue, so a delay here is a round trip through a queue whose only job is to
 * hold the message for a while. This declares those queues, and it is the one place in the
 * library that declares anything: a worker script calls it once at start-up, alongside the
 * declares it makes for its own topology.
 *
 * One queue per delay rather than one queue and a per-message expiration, because a classic
 * queue expires only from its head: a thirty-second message at the front holds a one-second
 * message behind it for the full thirty.
 */
readonly class RetryTopology
{
    /** What separates a queue's name from the delay its wait queue holds messages for. */
    protected const string WAIT_INFIX = '.wait.';

    /**
     * Declares one wait queue per delay, each dead-lettering back into $queue through the
     * default exchange. The queue itself is not declared here: it belongs to the application,
     * with arguments this has no business guessing.
     *
     * @param list<int> $delaysMs the delays to serve; `publish(delayMs: …)` accepts these
     *                            and nothing else
     */
    public static function declare(Channel $channel, string $queue, array $delaysMs, bool $durable = true): void
    {
        static::assertQueue($queue);

        if ($delaysMs === []) {
            throw new InvalidDelayException(
                message: "No delays were given for queue \"$queue\"; there would be nothing to declare.",
            );
        }

        $seen = [];

        foreach ($delaysMs as $delayMs) {
            static::assertDelay($delayMs);

            if (isset($seen[$delayMs])) {
                throw new InvalidDelayException(
                    message: "The delay $delayMs ms is listed twice for queue \"$queue\".",
                );
            }

            $seen[$delayMs] = true;

            $channel->queue(static::waitQueueName(queue: $queue, delayMs: $delayMs))->declare(
                durable: $durable,
                arguments: [
                    'x-message-ttl'             => $delayMs,
                    'x-dead-letter-exchange'    => '',
                    'x-dead-letter-routing-key' => $queue,
                ],
            );
        }
    }

    /**
     * The wait queue that holds a message for $delayMs before it returns to $queue. The name
     * is the contract between declare() and a delayed publish — both derive it, neither
     * remembers it.
     */
    public static function waitQueueName(string $queue, int $delayMs): string
    {
        static::assertQueue($queue);
        static::assertDelay($delayMs);

        return $queue . static::WAIT_INFIX . $delayMs;
    }

    protected static function assertQueue(string $queue): void
    {
        if ($queue === '') {
            throw new InvalidDelayException(
                message: 'A delayed message needs a named queue to come back to; the empty name is the default exchange.',
            );
        }
    }

    protected static function assertDelay(int $delayMs): void
    {
        if ($delayMs <= 0) {
            throw new InvalidDelayException(
                message: "A delay must be a positive number of milliseconds, got $delayMs.",
            );
        }
    }
}
