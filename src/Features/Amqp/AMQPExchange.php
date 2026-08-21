<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Features\Amqp\Payloads\ExchangeBindPayload;
use SConcur\Features\Amqp\Payloads\ExchangeBindPayloadParameters;
use SConcur\Features\Amqp\Payloads\ExchangeDeclarePayload;
use SConcur\Features\Amqp\Payloads\ExchangeDeclarePayloadParameters;
use SConcur\Features\Amqp\Payloads\ExchangeDeletePayload;
use SConcur\Features\Amqp\Payloads\ExchangeDeletePayloadParameters;
use SConcur\Features\Amqp\Payloads\ExchangeUnbindPayload;
use SConcur\Features\Amqp\Payloads\PublishPayload;
use SConcur\Features\Amqp\Payloads\PublishPayloadParameters;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Amqp\Support\CommandRunner;
use SConcur\Features\Amqp\Support\FlagsParser;
use SConcur\Features\Amqp\Support\PropertiesCodec;
use SConcur\Features\Amqp\Support\TableCodec;

/**
 * An exchange — the calque of ext-amqp's AMQPExchange. The name, the type, the flags and
 * the arguments are held here and cost nothing; only declareExchange(), delete(), bind(),
 * unbind() and publish() talk to the broker.
 */
class AMQPExchange extends AmqpResource
{
    protected AMQPConnection $connection;

    protected AMQPChannel $channel;

    protected ?string $name = null;

    protected ?string $type = null;

    protected bool $passive = false;

    protected bool $durable = false;

    protected bool $autoDelete = false;

    protected bool $internal = false;

    /** @var array<string, mixed> */
    protected array $arguments = [];

    /**
     * @throws AMQPChannelException if the channel is not open
     */
    public function __construct(AMQPChannel $channel)
    {
        if (!$channel->isConnected()) {
            throw new AMQPChannelException(
                message: 'Could not create exchange. No channel available.',
            );
        }

        $this->channel    = $channel;
        $this->connection = $channel->getConnection();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $exchangeName): void
    {
        $this->name = $exchangeName;
    }

    /** The flags currently set on this exchange, as the bit mask ext-amqp uses. */
    public function getFlags(): int
    {
        $flags = AMQP_NOPARAM;

        if ($this->passive) {
            $flags |= AMQP_PASSIVE;
        }

        if ($this->durable) {
            $flags |= AMQP_DURABLE;
        }

        if ($this->autoDelete) {
            $flags |= AMQP_AUTODELETE;
        }

        if ($this->internal) {
            $flags |= AMQP_INTERNAL;
        }

        return $flags;
    }

    /**
     * The flags to declare this exchange with: AMQP_PASSIVE, AMQP_DURABLE,
     * AMQP_AUTODELETE, AMQP_INTERNAL. Anything else in the mask is ignored.
     */
    public function setFlags(?int $flags): void
    {
        $this->passive    = FlagsParser::has(flags: $flags, flag: AMQP_PASSIVE);
        $this->durable    = FlagsParser::has(flags: $flags, flag: AMQP_DURABLE);
        $this->autoDelete = FlagsParser::has(flags: $flags, flag: AMQP_AUTODELETE);
        $this->internal   = FlagsParser::has(flags: $flags, flag: AMQP_INTERNAL);
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $exchangeType): void
    {
        $this->type = $exchangeType;
    }

    /**
     * @throws AMQPExchangeException if no such argument is set
     */
    public function getArgument(string $argumentName): mixed
    {
        if (!array_key_exists($argumentName, $this->arguments)) {
            throw new AMQPExchangeException(
                message: "The argument \"$argumentName\" does not exist",
            );
        }

        return $this->arguments[$argumentName];
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function setArgument(string $argumentName, mixed $argumentValue): void
    {
        $this->arguments[$argumentName] = $argumentValue;
    }

    public function removeArgument(string $argumentName): void
    {
        unset($this->arguments[$argumentName]);
    }

    /**
     * Replaces every argument at once.
     *
     * @param array<string, mixed> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    public function hasArgument(string $argumentName): bool
    {
        return array_key_exists($argumentName, $this->arguments);
    }

    /**
     * Declares the exchange on the broker. With AMQP_PASSIVE it only checks that the
     * exchange exists, and fails if it does not.
     *
     * @throws AMQPExchangeException if the broker rejects the declaration
     */
    public function declareExchange(): void
    {
        CommandRunner::run(
            payload: new ExchangeDeclarePayload(
                new ExchangeDeclarePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: (string) $this->name,
                    type: (string) $this->type,
                    passive: $this->passive,
                    durable: $this->durable,
                    autoDelete: $this->autoDelete,
                    internal: $this->internal,
                    noWait: false,
                    arguments: TableCodec::encode($this->arguments),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPExchangeException::class,
        );
    }

    /**
     * A synonym of declareExchange().
     *
     * @throws AMQPExchangeException if the broker rejects the declaration
     */
    public function declare(): void
    {
        $this->declareExchange();
    }

    /**
     * Deletes an exchange — this one, or the one named. With AMQP_IFUNUSED the broker
     * keeps it while something is still bound to it.
     *
     * @throws AMQPExchangeException if the broker rejects the deletion
     */
    public function delete(?string $exchangeName = null, ?int $flags = null): void
    {
        CommandRunner::run(
            payload: new ExchangeDeletePayload(
                new ExchangeDeletePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: $exchangeName ?? (string) $this->name,
                    ifUnused: FlagsParser::has(flags: $flags, flag: AMQP_IFUNUSED),
                    noWait: FlagsParser::has(flags: $flags, flag: AMQP_NOWAIT),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPExchangeException::class,
        );
    }

    /**
     * Routes the messages of the named exchange into this one.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws AMQPExchangeException if the broker rejects the binding
     */
    public function bind(string $exchangeName, ?string $routingKey = null, array $arguments = []): void
    {
        CommandRunner::run(
            payload: new ExchangeBindPayload(
                $this->bindParameters(
                    exchangeName: $exchangeName,
                    routingKey: $routingKey,
                    arguments: $arguments,
                ),
            ),
            exceptionClass: AMQPExchangeException::class,
        );
    }

    /**
     * Removes a binding made by bind().
     *
     * @param array<string, mixed> $arguments
     *
     * @throws AMQPExchangeException if the broker rejects the unbinding
     */
    public function unbind(string $exchangeName, ?string $routingKey = null, array $arguments = []): void
    {
        CommandRunner::run(
            payload: new ExchangeUnbindPayload(
                $this->bindParameters(
                    exchangeName: $exchangeName,
                    routingKey: $routingKey,
                    arguments: $arguments,
                ),
            ),
            exceptionClass: AMQPExchangeException::class,
        );
    }

    /**
     * Publishes a message through this exchange.
     *
     * The attributes are the ones ext-amqp accepts — content_type (text/plain unless
     * named), content_encoding, message_id, user_id, app_id, delivery_mode, priority,
     * timestamp, expiration, type, reply_to, correlation_id and headers. AMQP_MANDATORY
     * asks the broker to return a message it cannot route: the returns are collected by
     * AMQPChannel::waitForBasicReturn().
     *
     * @param array<string, mixed> $headers the message attributes
     *
     * @throws AMQPExchangeException if the message could not be handed to the broker
     */
    public function publish(
        string $message,
        ?string $routingKey = null,
        ?int $flags = null,
        array $headers = [],
    ): void {
        CommandRunner::run(
            payload: new PublishPayload(
                new PublishPayloadParameters(
                    channelId: $this->channel->internalId,
                    exchangeName: (string) $this->name,
                    routingKey: $routingKey ?? '',
                    mandatory: FlagsParser::has(flags: $flags, flag: AMQP_MANDATORY),
                    immediate: FlagsParser::has(flags: $flags, flag: AMQP_IMMEDIATE),
                    body: $message,
                    properties: PropertiesCodec::encode($headers),
                    timeoutMs: static::toMilliseconds($this->connection->getWriteTimeout()),
                ),
            ),
            exceptionClass: AMQPExchangeException::class,
        );
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    public function getConnection(): AMQPConnection
    {
        return $this->connection;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function bindParameters(string $exchangeName, ?string $routingKey, array $arguments): ExchangeBindPayloadParameters
    {
        return new ExchangeBindPayloadParameters(
            channelId: $this->channel->internalId,
            destination: (string) $this->name,
            source: $exchangeName,
            routingKey: $routingKey ?? '',
            noWait: false,
            arguments: TableCodec::encode($arguments),
            timeoutMs: $this->timeoutMs(),
        );
    }

    /** The deadline of one broker method, in milliseconds — the connection's rpc_timeout. */
    protected function timeoutMs(): int
    {
        return static::toMilliseconds($this->connection->getRpcTimeout());
    }
}
