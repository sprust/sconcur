<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A decimal field-table value: significand scaled down by 10^exponent.
 *
 * Marked final and readonly to match ext-amqp, one of the two places where the calque
 * overrides the project's "no final" rule (see docs/amqp.md).
 */
final readonly class AMQPDecimal implements AMQPValue
{
    public const int EXPONENT_MIN = 0;

    public const int EXPONENT_MAX = 255;

    public const int SIGNIFICAND_MIN = 0;

    public const int SIGNIFICAND_MAX = 4294967295;

    /**
     * @throws AMQPValueException if either part is out of the AMQP 0-9-1 range
     */
    public function __construct(
        protected int $exponent,
        protected int $significand,
    ) {
        if ($exponent < self::EXPONENT_MIN || $exponent > self::EXPONENT_MAX) {
            throw new AMQPValueException(
                message: 'Decimal exponent value must be less than ' . self::EXPONENT_MAX . '.',
            );
        }

        if ($significand < self::SIGNIFICAND_MIN || $significand > self::SIGNIFICAND_MAX) {
            throw new AMQPValueException(
                message: 'Decimal significand value must be less than ' . self::SIGNIFICAND_MAX . '.',
            );
        }
    }

    public function getExponent(): int
    {
        return $this->exponent;
    }

    public function getSignificand(): int
    {
        return $this->significand;
    }

    /**
     * The value as it goes into a field table: the object itself, which the encoder turns
     * into an AMQP decimal field. The extension does the same — a decimal keeps its kind
     * on the wire instead of collapsing into a float.
     */
    public function toAmqpValue(): mixed
    {
        return $this;
    }
}
