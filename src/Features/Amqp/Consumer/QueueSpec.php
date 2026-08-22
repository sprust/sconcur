<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

/**
 * One queue a QueueConsumer pulls: its name, how many coroutines pull it, and the
 * prefetch each of those coroutines asks for.
 *
 * The coroutine count is the weight — it is how a hot queue gets more capacity than a
 * quiet one inside a single worker, without a second worker pool. Every coroutine
 * opens its own channel and its own consumer on it, so the count is also the number
 * of channels this queue costs (see QueueSpecParser for the ceiling that puts on it).
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
