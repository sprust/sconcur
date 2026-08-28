<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

use LogicException;

/**
 * A prefetch limit was asked for outside the range AMQP 0-9-1 allows — a count above
 * 65535, a window above 4294967295 octets, or a negative one.
 *
 * A usage bug rather than a broker failure, so it is a LogicException and not an
 * AmqpException: the broker was never asked, and no reply code exists to carry
 * (.ai/README.md, "Exceptions").
 */
class InvalidPrefetchException extends LogicException
{
}
