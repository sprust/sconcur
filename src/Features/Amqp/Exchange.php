<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\ExchangeException;
use SConcur\Exceptions\Amqp\PublishConfirmTimeoutException;
use SConcur\Exceptions\Amqp\PublishNackedException;
use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Payloads\ExchangeBindPayload;
use SConcur\Features\Amqp\Payloads\ExchangeBindPayloadParameters;
use SConcur\Features\Amqp\Payloads\ExchangeDeclarePayload;
use SConcur\Features\Amqp\Payloads\ExchangeDeclarePayloadParameters;
use SConcur\Features\Amqp\Payloads\ExchangeDeletePayload;
use SConcur\Features\Amqp\Payloads\ExchangeDeletePayloadParameters;
use SConcur\Features\Amqp\Payloads\ExchangeUnbindPayload;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Amqp\Support\TableCodec;

/**
 * A named exchange on a channel — a handle, like Queue: building it talks to nobody.
 *
 * The empty name is the default exchange. It exists on every broker, cannot be declared or
 * deleted, and routes each message to the queue named by its routing key.
 */
class Exchange extends AmqpResource
{
    protected Channel $channel;

    protected string $name;

    public function __construct(Channel $channel, string $name = '')
    {
        $this->channel = $channel;
        $this->name    = $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** The channel this exchange's methods run on. */
    public function channel(): Channel
    {
        return $this->channel;
    }

    /**
     * Declares the exchange, creating it when it is not there. As with a queue, a
     * declaration that clashes with the exchange already there closes the channel.
     *
     * @param bool                 $durable    survive a broker restart
     * @param bool                 $autoDelete delete once the last binding goes
     * @param bool                 $internal   only other exchanges may publish to it
     * @param array<string, mixed> $arguments  the broker's own extensions
     *
     * @throws ExchangeException if the broker rejects the declaration
     */
    public function declare(
        ExchangeTypeEnum $type = ExchangeTypeEnum::Direct,
        bool $durable = false,
        bool $autoDelete = false,
        bool $internal = false,
        array $arguments = [],
    ): void {
        $this->runDeclare(
            type: $type,
            passive: false,
            durable: $durable,
            autoDelete: $autoDelete,
            internal: $internal,
            arguments: $arguments,
        );
    }

    /**
     * Asks whether the exchange exists, without creating or changing anything. One that is
     * not there is a 404 the broker answers by closing the channel.
     *
     * @throws ExchangeException if the exchange does not exist
     */
    public function declarePassive(): void
    {
        $this->runDeclare(type: ExchangeTypeEnum::Direct, passive: true);
    }

    /**
     * Binds this exchange to another, so what the other one routes by this key arrives
     * here and is routed on.
     *
     * @param string               $source    the exchange the messages come from
     * @param array<string, mixed> $arguments matching arguments, for a headers exchange
     *
     * @throws ExchangeException if the broker rejects the binding
     */
    public function bind(string $source, string $routingKey = '', array $arguments = []): void
    {
        $this->runCommand(
            payload: new ExchangeBindPayload(
                $this->bindParameters(source: $source, routingKey: $routingKey, arguments: $arguments),
            ),
            exceptionClass: ExchangeException::class,
            channel: $this->channel,
            operation: 'Could not bind the exchange.',
        );
    }

    /**
     * Removes a binding made by bind().
     *
     * @param array<string, mixed> $arguments
     *
     * @throws ExchangeException if the broker rejects the unbinding
     */
    public function unbind(string $source, string $routingKey = '', array $arguments = []): void
    {
        $this->runCommand(
            payload: new ExchangeUnbindPayload(
                $this->bindParameters(source: $source, routingKey: $routingKey, arguments: $arguments),
            ),
            exceptionClass: ExchangeException::class,
            channel: $this->channel,
            operation: 'Could not unbind the exchange.',
        );
    }

    /**
     * Deletes the exchange. The queues bound to it stay; only the routing goes.
     *
     * @param bool $ifUnused refuse to delete an exchange that still has bindings
     *
     * @throws ExchangeException if the broker rejects the deletion
     */
    public function delete(bool $ifUnused = false): void
    {
        $this->runCommand(
            payload: new ExchangeDeletePayload(
                new ExchangeDeletePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: $this->name,
                    ifUnused: $ifUnused,
                    noWait: false,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: ExchangeException::class,
            channel: $this->channel,
            operation: 'Could not delete the exchange.',
        );
    }

    /**
     * Publishes a message through this exchange. See Channel::publish().
     *
     * @throws ExchangeException if the message could not be handed to the broker
     */
    public function publish(Message|string $message, string $routingKey = '', bool $mandatory = false): void
    {
        $this->channel->publish(
            message: $message,
            exchange: $this->name,
            routingKey: $routingKey,
            mandatory: $mandatory,
        );
    }

    /**
     * Publishes through this exchange and waits for the broker to take responsibility for
     * the message. See Channel::publishConfirmed().
     *
     * @throws PublishNackedException if the broker refused to store the message
     * @throws UnroutableMessageException if it reached no queue
     * @throws PublishConfirmTimeoutException if the broker did not answer in time
     */
    public function publishConfirmed(
        Message|string $message,
        string $routingKey = '',
        float $timeout = 0.0,
        bool $mandatory = true,
    ): void {
        $this->channel->publishConfirmed(
            message: $message,
            exchange: $this->name,
            routingKey: $routingKey,
            timeout: $timeout,
            mandatory: $mandatory,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws ExchangeException if the broker rejects the declaration
     */
    protected function runDeclare(
        ExchangeTypeEnum $type,
        bool $passive,
        bool $durable = false,
        bool $autoDelete = false,
        bool $internal = false,
        array $arguments = [],
    ): void {
        $this->runCommand(
            payload: new ExchangeDeclarePayload(
                new ExchangeDeclarePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: $this->name,
                    type: $type->value,
                    passive: $passive,
                    durable: $durable,
                    autoDelete: $autoDelete,
                    internal: $internal,
                    noWait: false,
                    arguments: TableCodec::encode($arguments),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: ExchangeException::class,
            channel: $this->channel,
            operation: 'Could not declare the exchange.',
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function bindParameters(
        string $source,
        string $routingKey,
        array $arguments,
    ): ExchangeBindPayloadParameters {
        return new ExchangeBindPayloadParameters(
            channelId: $this->channel->internalId,
            destination: $this->name,
            source: $source,
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
