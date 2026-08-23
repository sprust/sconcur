<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\InvalidAmqpValueException;

/**
 * A decimal field-table value: significand scaled down by 10^exponent.
 *
 * AMQP 0-9-1 has a field kind of its own for this, so it travels as one rather than
 * collapsing into a float — a header flattened to a float would change type for every
 * other client reading the same queue.
 *
 * One thing to know before publishing a large significand: the field carries it as 32
 * bits, and RabbitMQ's own clients read those bits as a signed integer. A value above
 * 2^31-1 travels through SConcur bit for bit and reads back the same here, but another
 * client sees it as negative. A negative decimal cannot be expressed at all, which is why
 * SIGNIFICAND_MIN is zero.
 */
readonly class Decimal
{
    public const int EXPONENT_MIN = 0;

    public const int EXPONENT_MAX = 255;

    public const int SIGNIFICAND_MIN = 0;

    public const int SIGNIFICAND_MAX = 4294967295;

    /**
     * @throws InvalidAmqpValueException if either part is out of the AMQP 0-9-1 range
     */
    public function __construct(
        public int $exponent,
        public int $significand,
    ) {
        if ($exponent < self::EXPONENT_MIN || $exponent > self::EXPONENT_MAX) {
            throw new InvalidAmqpValueException(
                message: 'Decimal exponent must be between ' . self::EXPONENT_MIN . ' and ' . self::EXPONENT_MAX . '.',
            );
        }

        if ($significand < self::SIGNIFICAND_MIN || $significand > self::SIGNIFICAND_MAX) {
            throw new InvalidAmqpValueException(
                message: 'Decimal significand must be between '
                    . self::SIGNIFICAND_MIN . ' and ' . self::SIGNIFICAND_MAX . '.',
            );
        }
    }

    /** The number this stands for. Lossy past the precision of a float, as any decimal is. */
    public function toFloat(): float
    {
        return $this->significand / (10 ** $this->exponent);
    }
}
