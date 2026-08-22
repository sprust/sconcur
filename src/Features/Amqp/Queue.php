<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use Generator;
use SConcur\Exceptions\Amqp\ExchangeException;
use SConcur\Exceptions\Amqp\PublishConfirmTimeoutException;
use SConcur\Exceptions\Amqp\PublishNackedException;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Payloads\QueueBindPayload;
use SConcur\Features\Amqp\Payloads\QueueBindPayloadParameters;
use SConcur\Features\Amqp\Payloads\QueueDeclarePayload;
use SConcur\Features\Amqp\Payloads\QueueDeclarePayloadParameters;
use SConcur\Features\Amqp\Payloads\QueueDeletePayload;
use SConcur\Features\Amqp\Payloads\QueueDeletePayloadParameters;
use SConcur\Features\Amqp\Payloads\QueuePurgePayload;
use SConcur\Features\Amqp\Payloads\QueuePurgePayloadParameters;
use SConcur\Features\Amqp\Payloads\QueueUnbindPayload;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Amqp\Support\TableCodec;

/**
 * A named queue on a channel. The object is a handle: building it talks to nobody, and the
 * broker only hears about the queue when one of these methods is called.
 *
 * The calque made a queue carry the settings of its declaration in mutable fields, so a
 * declaration was a run of setters ending in `declareQueue()`. Here a declaration is one
 * call with its arguments, and the handle keeps only the name.
 */
class Queue extends AmqpResource
{
    protected Channel $channel;

    protected string $name;

    public function __construct(Channel $channel, string $name = '')
    {
        $this->channel = $channel;
        $this->name    = $name;
    }

    /**
     * The queue's name. Empty until a declaration of an unnamed queue comes back with the
     * one the broker generated.
     */
    public function name(): string
    {
        return $this->name;
    }

    /** The channel this queue's methods run on. */
    public function channel(): Channel
    {
        return $this->channel;
    }

    /**
     * Declares the queue, creating it when it is not there. Declaring an existing queue
     * with settings that differ from the ones it was created with is an error the broker
     * answers by closing the channel, so a queue is declared by whoever owns it.
     *
     * An empty name asks the broker to generate one, which arrives in the answer and
     * becomes this handle's name.
     *
     * @param bool                 $durable    survive a broker restart
     * @param bool                 $exclusive  only this connection may use it, and it goes
     *                                         when the connection does
     * @param bool                 $autoDelete delete once the last consumer leaves
     * @param array<string, mixed> $arguments  the broker's own extensions, such as
     *                                         `x-max-priority` or `x-dead-letter-exchange`
     *
     * @throws QueueException if the broker rejects the declaration
     */
    public function declare(
        bool $durable = false,
        bool $exclusive = false,
        bool $autoDelete = false,
        array $arguments = [],
    ): QueueInfo {
        return $this->runDeclare(
            passive: false,
            durable: $durable,
            exclusive: $exclusive,
            autoDelete: $autoDelete,
            arguments: $arguments,
        );
    }

    /**
     * Asks whether the queue exists and how much is in it, without creating or changing
     * anything. A queue that is not there is a 404 the broker answers by closing the
     * channel — that is what passive means in AMQP, and it is why this is a separate call
     * rather than a flag on declare().
     *
     * @throws QueueException if the queue does not exist
     */
    public function declarePassive(): QueueInfo
    {
        return $this->runDeclare(passive: true);
    }

    /**
     * Binds the queue to an exchange, so the messages that exchange routes by this key
     * reach it.
     *
     * @param array<string, mixed> $arguments matching arguments, for a headers exchange
     *
     * @throws QueueException if the broker rejects the binding
     */
    public function bind(string $exchange, string $routingKey = '', array $arguments = []): void
    {
        $this->runCommand(
            payload: new QueueBindPayload(
                $this->bindParameters(exchange: $exchange, routingKey: $routingKey, arguments: $arguments),
            ),
            exceptionClass: QueueException::class,
            channel: $this->channel,
            operation: 'Could not bind the queue.',
        );
    }

    /**
     * Removes a binding made by bind().
     *
     * @param array<string, mixed> $arguments
     *
     * @throws QueueException if the broker rejects the unbinding
     */
    public function unbind(string $exchange, string $routingKey = '', array $arguments = []): void
    {
        $this->runCommand(
            payload: new QueueUnbindPayload(
                $this->bindParameters(exchange: $exchange, routingKey: $routingKey, arguments: $arguments),
            ),
            exceptionClass: QueueException::class,
            channel: $this->channel,
            operation: 'Could not unbind the queue.',
        );
    }

    /**
     * Removes every message from the queue and answers how many that was.
     *
     * @throws QueueException if the broker rejects the purge
     */
    public function purge(): int
    {
        $result = $this->runCommand(
            payload: new QueuePurgePayload(
                new QueuePurgePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: $this->name,
                    noWait: false,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: QueueException::class,
            channel: $this->channel,
            operation: 'Could not purge the queue.',
        );

        return isset($result['mc']) ? (int) $result['mc'] : 0;
    }

    /**
     * Deletes the queue with everything still in it, and answers how many messages went
     * with it.
     *
     * @param bool $ifUnused refuse to delete a queue that still has consumers
     * @param bool $ifEmpty  refuse to delete a queue that still has messages
     *
     * @throws QueueException if the broker rejects the deletion
     */
    public function delete(bool $ifUnused = false, bool $ifEmpty = false): int
    {
        $result = $this->runCommand(
            payload: new QueueDeletePayload(
                new QueueDeletePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: $this->name,
                    ifUnused: $ifUnused,
                    ifEmpty: $ifEmpty,
                    noWait: false,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: QueueException::class,
            channel: $this->channel,
            operation: 'Could not delete the queue.',
        );

        return isset($result['mc']) ? (int) $result['mc'] : 0;
    }

    /**
     * One message, or null when the queue is empty. See Channel::get().
     *
     * @throws QueueException if the broker rejects the request
     */
    public function get(bool $autoAck = false): ?Delivery
    {
        return $this->channel->get(queueName: $this->name, autoAck: $autoAck);
    }

    /**
     * Consumes the queue. See Channel::consume() for what owns the consumer and when the
     * stream ends.
     *
     * @param array<string, mixed> $arguments
     *
     * @return Generator<int, Delivery>
     *
     * @throws QueueException if the broker rejects the consumer or the stream fails
     */
    public function consume(
        bool $autoAck = false,
        string $consumerTag = '',
        bool $exclusive = false,
        bool $noLocal = false,
        array $arguments = [],
    ): Generator {
        return $this->channel->consume(
            queueName: $this->name,
            autoAck: $autoAck,
            consumerTag: $consumerTag,
            exclusive: $exclusive,
            noLocal: $noLocal,
            arguments: $arguments,
        );
    }

    /**
     * Publishes straight into this queue.
     *
     * The default exchange routes by queue name, which is how every AMQP client reaches one
     * queue without an exchange of its own — and which neither ext-amqp nor php-amqplib
     * spares the caller from knowing. Here it is the obvious call.
     *
     * @throws ExchangeException if the message could not be handed to the broker
     */
    public function publish(Message|string $message, bool $mandatory = false): void
    {
        $this->channel->publish(message: $message, exchange: '', routingKey: $this->name, mandatory: $mandatory);
    }

    /**
     * Publishes into this queue and waits for the broker to take responsibility for the
     * message. See Channel::publishConfirmed().
     *
     * @throws PublishNackedException if the broker refused to store the message
     * @throws UnroutableMessageException if the queue does not exist
     * @throws PublishConfirmTimeoutException if the broker did not answer in time
     */
    public function publishConfirmed(Message|string $message, float $timeout = 0.0, bool $mandatory = true): void
    {
        $this->channel->publishConfirmed(
            message: $message,
            exchange: '',
            routingKey: $this->name,
            timeout: $timeout,
            mandatory: $mandatory,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws QueueException if the broker rejects the declaration
     */
    protected function runDeclare(
        bool $passive,
        bool $durable = false,
        bool $exclusive = false,
        bool $autoDelete = false,
        array $arguments = [],
    ): QueueInfo {
        $result = $this->runCommand(
            payload: new QueueDeclarePayload(
                new QueueDeclarePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: $this->name,
                    passive: $passive,
                    durable: $durable,
                    exclusive: $exclusive,
                    autoDelete: $autoDelete,
                    noWait: false,
                    arguments: TableCodec::encode($arguments),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: QueueException::class,
            channel: $this->channel,
            operation: 'Could not declare the queue.',
        );

        if (isset($result['na'])) {
            $this->name = (string) $result['na'];
        }

        return new QueueInfo(
            name: $this->name,
            messageCount: isset($result['mc']) ? (int) $result['mc'] : 0,
            consumerCount: isset($result['cc']) ? (int) $result['cc'] : 0,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function bindParameters(string $exchange, string $routingKey, array $arguments): QueueBindPayloadParameters
    {
        return new QueueBindPayloadParameters(
            channelId: $this->channel->internalId,
            queueName: $this->name,
            exchangeName: $exchange,
            routingKey: $routingKey,
            noWait: false,
            arguments: TableCodec::encode($arguments),
            timeoutMs: $this->timeoutMs(),
        );
    }

    /** The deadline of one broker method, in milliseconds — the connection's rpc timeout. */
    protected function timeoutMs(): int
    {
        return static::toMilliseconds($this->channel->connection()->options->rpcTimeout);
    }
}
