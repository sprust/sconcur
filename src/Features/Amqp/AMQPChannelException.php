<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A failure of a channel operation — the channel is closed, or the broker rejected the method.
 */
class AMQPChannelException extends AMQPException
{
}
