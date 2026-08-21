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
     * The value as it travels to the broker — the extension hands the field table a
     * float, and so does the calque.
     */
    public function toAmqpValue(): mixed
    {
        return $this->significand / (10 ** $this->exponent);
    }
}
