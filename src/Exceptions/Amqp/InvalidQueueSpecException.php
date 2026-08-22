<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

use LogicException;

/**
 * The queue list a QueueConsumer was launched with does not describe queues it can
 * consume: malformed JSON, a missing or duplicated name, a non-positive coroutine
 * count, or more coroutines than one connection has channels for. A usage bug in how
 * the consumer was configured, caught before the first basic.consume rather than as a
 * broker error under load.
 */
class InvalidQueueSpecException extends LogicException
{
}
