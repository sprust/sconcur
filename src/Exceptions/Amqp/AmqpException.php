<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

use RuntimeException;

/**
 * The base of every AMQP failure. A broker that refuses a method answers with a reply
 * code, and that code is the exception's own code, so `getCode()` reads 404 for a queue
 * that is not there and 406 for a declaration that clashes with an existing one.
 *
 * It extends RuntimeException because talking to a broker fails at runtime, whatever the
 * caller does — the project's rule for the kind (.ai/README.md, "Exceptions").
 */
class AmqpException extends RuntimeException
{
}
