<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

use LogicException;

/**
 * A delay was asked for that no wait queue can serve — zero or negative milliseconds, or a
 * list of delays holding the same value twice.
 *
 * A usage bug rather than a broker failure, so it is a LogicException and not an
 * AmqpException: the broker was never asked, and no reply code exists to carry
 * (.ai/README.md, "Exceptions").
 */
class InvalidDelayException extends LogicException
{
}
