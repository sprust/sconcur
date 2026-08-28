<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

/**
 * The channel is gone. A broker that answers a method with a channel-level error closes
 * the channel as part of the answer, so the next command on it cannot succeed either and
 * is refused here rather than sent.
 */
class ChannelException extends AmqpException
{
}
