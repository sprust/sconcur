<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

use LogicException;

/**
 * One delivery was used from two coroutines at once.
 *
 * A delivery belongs to the handler it was given to, and the channel it hands out is lent to
 * that handler alone. Two coroutines asking it for a channel at the same time would each be
 * lent one, and only one of them could ever be given back — the other would be held by the
 * pool for the life of the worker, along with a channel number and eventually a socket.
 *
 * Raised rather than leaked: give each coroutine a channel of its own from the connection.
 *
 * A usage bug rather than a broker failure, so it is a LogicException and not an
 * AmqpException (.ai/README.md, "Exceptions").
 */
class ConcurrentDeliveryUseException extends LogicException
{
}
