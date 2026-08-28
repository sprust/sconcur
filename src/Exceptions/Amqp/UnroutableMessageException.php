<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

use SConcur\Features\Amqp\Message;

/**
 * A message published with `mandatory: true` reached no queue and the broker sent it back.
 *
 * The calque reported this through a callback registered on the channel, because the
 * extension has nowhere else to put it. Here the publish is what waits for the broker, so
 * the return is what that wait failed with, and the message comes back with it — a
 * publisher that has to re-route or store the message has it in hand at the point of the
 * failure rather than in a callback that fires later.
 */
class UnroutableMessageException extends AmqpException
{
    public function __construct(
        string $message,
        protected Message $returnedMessage,
        protected string $exchange,
        protected string $routingKey,
        int $replyCode = 0,
    ) {
        parent::__construct(message: $message, code: $replyCode);
    }

    /** The message the broker handed back, properties and all. */
    public function getReturnedMessage(): Message
    {
        return $this->returnedMessage;
    }

    /** The exchange it was published to. */
    public function getExchange(): string
    {
        return $this->exchange;
    }

    /** The routing key that matched nothing. */
    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }
}
