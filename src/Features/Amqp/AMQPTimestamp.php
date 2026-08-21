<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use Stringable;

/**
 * A timestamp field-table value, in seconds since the Unix epoch.
 *
 * Marked final and readonly to match ext-amqp, one of the two places where the calque
 * overrides the project's "no final" rule (see docs/amqp.md).
 */
final readonly class AMQPTimestamp implements AMQPValue, Stringable
{
    public const float MIN = 0.0;

    public const float MAX = 18446744073709551616.0;

    /**
     * @throws AMQPValueException if the timestamp is out of the AMQP 0-9-1 range
     */
    public function __construct(
        protected float $timestamp,
    ) {
        if ($timestamp < self::MIN || $timestamp > self::MAX) {
            throw new AMQPValueException(
                message: 'The timestamp parameter must be within range ' . self::MIN . ' and ' . self::MAX . '.',
            );
        }
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * The value as it travels to the broker — whole seconds, as the extension sends.
     */
    public function toAmqpValue(): mixed
    {
        return (int) $this->timestamp;
    }

    public function __toString(): string
    {
        return sprintf('%.0F', $this->timestamp);
    }
}
