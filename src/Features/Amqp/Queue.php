<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use Generator;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Features\Amqp\Support\TableCodec;

/**
 * A named queue on a channel — a handle: building it talks to nobody, and the broker only
 * hears about the queue when one of these methods is called.
 *
 * It owns nothing on the Go side, so its methods run through Channel::run().
 */
class Queue
{
    protected Channel $channel;

    protected string $name;

    public function __construct(Channel $channel, string $name = '')
    {
        $this->channel = $channel;
        $this->name    = $name;
    }

    /** Empty until a declaration of an unnamed queue comes back with the broker's own. */
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
     * Declares the queue, creating it when it is not there. Redeclaring an existing one with
     * different settings closes the channel, so a queue is declared by whoever owns it.
     *
     * An empty name asks the broker to generate one, which becomes this handle's name.
     *
     * @param bool                 $durable    survive a broker restart
     * @param bool                 $exclusive  only this connection may use it, and it goes
     *                                         when the connection does
     * @param bool                 $autoDelete delete once the last consumer leaves
     * @param array<string, mixed> $arguments  the broker's own extensions, such as
     *                                         `x-max-priority` or `x-dead-letter-exchange`
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
     * Asks whether the queue exists and how much is in it, creating nothing. One that is not
     * there is a 404 the broker answers by closing the channel — which is why this is a call
     * of its own rather than a flag on declare().
     */
    public function declarePassive(): QueueInfo
    {
        return $this->runDeclare(passive: true);
    }

    /**
     * Binds the queue to an exchange, so what that exchange routes by this key reaches it.
     *
     * @param array<string, mixed> $arguments matching arguments, for a headers exchange
     */
    public function bind(string $exchange, string $routingKey = '', array $arguments = []): void
    {
        $this->channel->run(
            command: AmqpCommandEnum::QueueBind,
            data: $this->bindData(
                exchange: $exchange,
                routingKey: $routingKey,
                arguments: $arguments,
            ),
            exceptionClass: QueueException::class,
            operation: 'Could not bind the queue.',
        );
    }

    /**
     * Removes a binding made by bind().
     *
     * @param array<string, mixed> $arguments
     */
    public function unbind(string $exchange, string $routingKey = '', array $arguments = []): void
    {
        $this->channel->run(
            command: AmqpCommandEnum::QueueUnbind,
            data: $this->bindData(
                exchange: $exchange,
                routingKey: $routingKey,
                arguments: $arguments,
            ),
            exceptionClass: QueueException::class,
            operation: 'Could not unbind the queue.',
        );
    }

    /**
     * Removes every message from the queue and answers how many that was.
     */
    public function purge(): int
    {
        $result = $this->channel->run(
            command: AmqpCommandEnum::QueuePurge,
            data: [
                'na' => $this->name,
                'nw' => false,
            ],
            exceptionClass: QueueException::class,
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
     */
    public function delete(bool $ifUnused = false, bool $ifEmpty = false): int
    {
        $result = $this->channel->run(
            command: AmqpCommandEnum::QueueDelete,
            data: [
                'na' => $this->name,
                'iu' => $ifUnused,
                'ie' => $ifEmpty,
                'nw' => false,
            ],
            exceptionClass: QueueException::class,
            operation: 'Could not delete the queue.',
        );

        return isset($result['mc']) ? (int) $result['mc'] : 0;
    }

    /**
     * One message, or null when the queue is empty. See Channel::get().
     */
    public function get(bool $autoAck = false): ?Delivery
    {
        return $this->channel->get(
            queueName: $this->name,
            autoAck: $autoAck,
        );
    }

    /**
     * Consumes the queue. See Channel::consume() for what owns the consumer and when the
     * stream ends.
     *
     * @param array<string, mixed> $arguments
     *
     * @return Generator<int, Delivery>
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
     * Publishes straight into this queue, through the default exchange that routes by queue
     * name.
     */
    public function publish(Message|string $message, bool $mandatory = false): void
    {
        $this->channel->publish(
            message: $message,
            exchange: '',
            routingKey: $this->name,
            mandatory: $mandatory,
        );
    }

    /**
     * Publishes into this queue and waits for the broker to take responsibility for the
     * message. See Channel::publishConfirmed().
     */
    public function publishConfirmed(
        Message|string $message,
        float $timeoutSeconds = 0.0,
        bool $mandatory = true,
    ): void {
        $this->channel->publishConfirmed(
            message: $message,
            exchange: '',
            routingKey: $this->name,
            timeoutSeconds: $timeoutSeconds,
            mandatory: $mandatory,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function runDeclare(
        bool $passive,
        bool $durable = false,
        bool $exclusive = false,
        bool $autoDelete = false,
        array $arguments = [],
    ): QueueInfo {
        $result = $this->channel->run(
            command: AmqpCommandEnum::QueueDeclare,
            data: [
                'na' => $this->name,
                'pa' => $passive,
                'du' => $durable,
                'ex' => $exclusive,
                'ad' => $autoDelete,
                'nw' => false,
                'ar' => TableCodec::encode($arguments),
            ],
            exceptionClass: QueueException::class,
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
     *
     * @return array<string, mixed>
     */
    protected function bindData(string $exchange, string $routingKey, array $arguments): array
    {
        return [
            'na' => $this->name,
            'en' => $exchange,
            'rk' => $routingKey,
            'nw' => false,
            'ar' => TableCodec::encode($arguments),
        ];
    }
}
