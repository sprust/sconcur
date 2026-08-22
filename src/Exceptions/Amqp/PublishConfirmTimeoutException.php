<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

/**
 * The broker answered neither ack nor nack within the deadline. The message may still be
 * on its way: a timeout says nothing about what the broker did with it, only that the
 * publisher stopped waiting.
 */
class PublishConfirmTimeoutException extends AmqpException
{
}
