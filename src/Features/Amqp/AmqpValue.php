<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * The extension point for an application's own field-table values: a class that implements
 * this is asked what it stands for instead of being refused, and may answer with a scalar,
 * an array, a Decimal or a Timestamp.
 *
 * ```php
 * readonly class Money implements AmqpValue
 * {
 *     public function __construct(public int $cents) {}
 *
 *     public function toAmqpValue(): mixed
 *     {
 *         return new Decimal(
 *             exponent: 2,
 *             significand: $this->cents,
 *         );
 *     }
 * }
 *
 * $queue->publish(new Message($body, headers: ['price' => new Money(1999)]));
 * ```
 *
 * Decimal and Timestamp do not implement it: TableCodec recognises them by class, because
 * they are the two field kinds it actually writes.
 */
interface AmqpValue
{
    public function toAmqpValue(): mixed;
}
