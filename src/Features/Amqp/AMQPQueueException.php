<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A failure of a queue operation (declare, bind, get, consume, delete).
 */
class AMQPQueueException extends AMQPException
{
}
