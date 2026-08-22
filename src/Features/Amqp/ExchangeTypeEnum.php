<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * How an exchange decides which queues a message goes to. The values are the names AMQP
 * 0-9-1 puts on the wire.
 */
enum ExchangeTypeEnum: string
{
    /** Routes to the queues bound with exactly this routing key. */
    case Direct = 'direct';

    /** Routes to every bound queue, whatever the routing key. */
    case Fanout = 'fanout';

    /** Routes by a dotted pattern: `*` matches one word, `#` matches any number. */
    case Topic = 'topic';

    /** Routes by the message headers instead of the routing key. */
    case Headers = 'headers';
}
