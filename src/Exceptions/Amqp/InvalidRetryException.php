<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

use LogicException;

/**
 * A retry was described in a way that cannot be carried out — a negative number of attempts,
 * or a schedule holding a negative wait.
 *
 * A usage bug rather than a broker failure, so it is a LogicException and not an
 * AmqpException: the broker was never asked, and no reply code exists to carry
 * (.ai/README.md, "Exceptions").
 */
class InvalidRetryException extends LogicException
{
}
