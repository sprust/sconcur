<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

/**
 * The broker refused to take responsibility for a published message: it answered
 * basic.nack instead of basic.ack. The message is lost unless the publisher sends it
 * again — a nack means the broker could not store it, not that it was rejected by a
 * consumer.
 */
class PublishNackedException extends AmqpException
{
}
