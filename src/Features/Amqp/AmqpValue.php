<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A field-table value that knows how to present itself as an AMQP value. Implemented by
 * Decimal and Timestamp; an application may implement it for its own types, and the
 * encoder will ask what they stand for instead of refusing them.
 */
interface AmqpValue
{
    public function toAmqpValue(): mixed;
}
