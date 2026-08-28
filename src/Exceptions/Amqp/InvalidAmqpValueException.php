<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

/**
 * A value cannot travel in an AMQP field table: a decimal whose exponent or significand is
 * outside the range the protocol encodes, a timestamp before the epoch or past the
 * unsigned 64-bit seconds AMQP counts, a table nested deeper than the protocol allows.
 */
class InvalidAmqpValueException extends AmqpException
{
}
