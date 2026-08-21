<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A field-table value that knows how to present itself as an AMQP value. Implemented by
 * AMQPDecimal and AMQPTimestamp; an application may implement it for its own types, as
 * with ext-amqp.
 */
interface AMQPValue
{
    public function toAmqpValue(): mixed;
}
