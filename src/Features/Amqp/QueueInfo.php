<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * What the broker answered a queue.declare with.
 *
 * `ext-amqp` returns the message count alone, so the calque threw the rest away. The
 * broker sends the consumer count in the same reply, and it is what tells a worker
 * whether anyone else is already reading the queue it just declared.
 */
readonly class QueueInfo
{
    public function __construct(
        /** The queue's name — the one the broker generated, when the declaration asked for one. */
        public string $name,
        /** Messages ready for delivery at the moment of the declaration. */
        public int $messageCount,
        /** Consumers attached to the queue at that moment. */
        public int $consumerCount,
    ) {
    }
}
