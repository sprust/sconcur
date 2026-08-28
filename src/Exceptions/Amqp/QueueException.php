<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

/**
 * The broker refused a queue method: a declaration that clashes with the queue already
 * there, a binding to an exchange that does not exist, a consumer on a queue that is
 * exclusive to someone else.
 */
class QueueException extends AmqpException
{
}
