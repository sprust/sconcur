<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A failure of the connection to the broker: it could not be established, it was refused, or it died mid-operation.
 */
class AMQPConnectionException extends AMQPException
{
}
