<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

/**
 * The connection is unusable: the broker is unreachable, it refused the login, or a
 * failure took the whole connection down rather than one channel. Every channel opened on
 * it is closed with it.
 */
class ConnectionException extends AmqpException
{
}
