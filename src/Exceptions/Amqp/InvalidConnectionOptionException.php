<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

use LogicException;

/**
 * A connection was described in a way no broker can be asked for: an option outside the
 * range AMQP 0-9-1 allows, a URI that cannot be read, a client key named without its
 * certificate, or SASL EXTERNAL asked for with no certificate to authenticate with.
 *
 * A usage bug rather than a broker failure, so it is a LogicException and not an
 * AmqpException: nothing was sent, and there is no reply code to carry
 * (.ai/README.md, "Exceptions").
 */
class InvalidConnectionOptionException extends LogicException
{
}
