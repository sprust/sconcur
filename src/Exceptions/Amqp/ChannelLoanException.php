<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

/**
 * A handler asked its delivery for a channel and the runtime could not lend one.
 *
 * Its own class because it says something no other failure does: the job never ran. A
 * handler that throws has looked at the message and decided against it, and where its
 * message goes is the worker's policy to choose; a message whose handler was never given
 * the means to start has been decided about by nobody, so it goes back to the broker
 * whatever that policy says.
 *
 * The failure underneath — a connection the broker closed, a channel the pool could not
 * open — is the `previous`.
 */
class ChannelLoanException extends AmqpException
{
}
