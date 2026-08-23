<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

/**
 * One queue a QueueConsumer pulls.
 *
 * The coroutine count is the queue's weight — how a hot queue gets more capacity than a
 * quiet one inside a single worker. Each coroutine opens a channel of its own, so the count
 * is also what this queue costs against the connection's channel budget (QueueSpecParser).
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
