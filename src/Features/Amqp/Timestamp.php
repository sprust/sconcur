<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\InvalidAmqpValueException;
use Stringable;

/**
 * A timestamp field-table value, in whole seconds since the Unix epoch.
 *
 * As with Decimal, AMQP 0-9-1 has a field kind for this and the value keeps it on the
 * wire instead of arriving at other clients as a plain integer.
 *
 * Held as a float because AMQP counts unsigned 64-bit seconds and a PHP int is signed:
 * the upper half of the protocol's range has no int to hold it. What can actually be
 * published is the narrower range a PHP int does cover — see TableCodec.
 */
readonly class Timestamp implements Stringable
{
    public const float MIN_SECONDS = 0.0;

    public const float MAX_SECONDS = 18446744073709551616.0;

    public float $seconds;

    public function __construct(float $seconds)
    {
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new InvalidAmqpValueException(
                message: 'A timestamp must be between ' . self::MIN_SECONDS . ' and ' . self::MAX_SECONDS . '.',
            );
        }

        // AMQP counts whole seconds: a value from microtime(true) is truncated here
        // rather than rounded up a second later.
        $this->seconds = floor($seconds);
    }

    /** The seconds as a plain decimal string, with no exponent whatever their size. */
    public function __toString(): string
    {
        return sprintf('%.0F', $this->seconds);
    }
}
