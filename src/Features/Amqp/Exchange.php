<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\ExchangeException;
use SConcur\Features\Amqp\Support\TableCodec;

/**
 * A named exchange on a channel — a handle, like Queue, running its methods through
 * Channel::run().
 *
 * The empty name is the default exchange: it exists on every broker, cannot be declared or
 * deleted, and routes each message to the queue named by its routing key.
 */
class Exchange
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
     * Declares the exchange, creating it when it is not there. As with a queue, a clashing
     * declaration closes the channel.
     *
     * @param bool                 $durable    survive a broker restart
     * @param bool                 $autoDelete delete once the last binding goes
     * @param bool                 $internal   only other exchanges may publish to it
     * @param array<string, mixed> $arguments  the broker's own extensions
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
     * Asks whether the exchange exists, creating nothing. One that is not there is a 404 the
     * broker answers by closing the channel.
     */
    public function declarePassive(): void
    {
        $this->runDeclare(
            type: ExchangeTypeEnum::Direct,
            passive: true,
        );
    }

    /**
     * Binds this exchange to another, so what that one routes by this key arrives here and
     * is routed on.
     *
     * @param string               $source    the exchange the messages come from
     * @param array<string, mixed> $arguments matching arguments, for a headers exchange
     */
    public function bind(string $source, string $routingKey = '', array $arguments = []): void
    {
        $this->channel->run(
            command: AmqpCommandEnum::ExchangeBind,
            data: $this->bindData(
                source: $source,
                routingKey: $routingKey,
                arguments: $arguments,
            ),
            exceptionClass: ExchangeException::class,
            operation: 'Could not bind the exchange.',
        );
    }

    /**
     * Removes a binding made by bind().
     *
     * @param array<string, mixed> $arguments
     */
    public function unbind(string $source, string $routingKey = '', array $arguments = []): void
    {
        $this->channel->run(
            command: AmqpCommandEnum::ExchangeUnbind,
            data: $this->bindData(
                source: $source,
                routingKey: $routingKey,
                arguments: $arguments,
            ),
            exceptionClass: ExchangeException::class,
            operation: 'Could not unbind the exchange.',
        );
    }

    /**
     * Deletes the exchange. The queues bound to it stay; only the routing goes.
     *
     * @param bool $ifUnused refuse to delete an exchange that still has bindings
     */
    public function delete(bool $ifUnused = false): void
    {
        $this->channel->run(
            command: AmqpCommandEnum::ExchangeDelete,
            data: [
                'na' => $this->name,
                'iu' => $ifUnused,
                'nw' => false,
            ],
            exceptionClass: ExchangeException::class,
            operation: 'Could not delete the exchange.',
        );
    }

    /**
     * Publishes a message through this exchange. See Channel::publish().
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
     */
    public function publishConfirmed(
        Message|string $message,
        string $routingKey = '',
        float $timeoutSeconds = 0.0,
        bool $mandatory = true,
    ): void {
        $this->channel->publishConfirmed(
            message: $message,
            exchange: $this->name,
            routingKey: $routingKey,
            timeoutSeconds: $timeoutSeconds,
            mandatory: $mandatory,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function runDeclare(
        ExchangeTypeEnum $type,
        bool $passive,
        bool $durable = false,
        bool $autoDelete = false,
        bool $internal = false,
        array $arguments = [],
    ): void {
        $this->channel->run(
            command: AmqpCommandEnum::ExchangeDeclare,
            data: [
                'na' => $this->name,
                'ty' => $type->value,
                'pa' => $passive,
                'du' => $durable,
                'ad' => $autoDelete,
                'in' => $internal,
                'nw' => false,
                'ar' => TableCodec::encode($arguments),
            ],
            exceptionClass: ExchangeException::class,
            operation: 'Could not declare the exchange.',
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function bindData(string $source, string $routingKey, array $arguments): array
    {
        return [
            'ds' => $this->name,
            'sr' => $source,
            'rk' => $routingKey,
            'nw' => false,
            'ar' => TableCodec::encode($arguments),
        ];
    }
}
