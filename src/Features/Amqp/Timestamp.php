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
 */
readonly class Timestamp implements AmqpValue, Stringable
{
    public const float MIN = 0.0;

    public const float MAX = 18446744073709551616.0;

    public float $seconds;

    /**
     * @throws InvalidAmqpValueException if the timestamp is out of the AMQP 0-9-1 range
     */
    public function __construct(float $seconds)
    {
        if ($seconds < self::MIN || $seconds > self::MAX) {
            throw new InvalidAmqpValueException(
                message: 'A timestamp must be between ' . self::MIN . ' and ' . self::MAX . '.',
            );
        }

        // AMQP counts whole seconds: a value from microtime(true) is truncated here
        // rather than rounded up a second later.
        $this->seconds = floor($seconds);
    }

    public function toAmqpValue(): mixed
    {
        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%.0F', $this->seconds);
    }
}
