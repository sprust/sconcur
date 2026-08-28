<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

/**
 * One queue a QueueConsumer pulls.
 *
 * The coroutine count is the queue's weight — how a hot queue gets more capacity than a
 * quiet one inside a single worker. Each unit of it opens a consumer on a channel of its
 * own, so the count is also what this queue costs against the connection's channel budget
 * (QueueSpecParser).
 *
 * How many handlers that puts in flight is the weight times the prefetch: one consumer may
 * hold several unacknowledged messages, and each of those is a coroutine. The channels a
 * handler publishes on are not these — they are lent from a pool of their own
 * (PublishChannelPool).
 */
readonly class QueueSpec
{
    /**
     * @param int  $coroutineCount how many coroutines consume this queue
     * @param ?int $prefetchCount  unacknowledged messages one of those coroutines may
     *                             hold; null takes the consumer-wide value
     */
    public function __construct(
        public string $name,
        public int $coroutineCount = 1,
        public ?int $prefetchCount = null,
    ) {
    }
}
