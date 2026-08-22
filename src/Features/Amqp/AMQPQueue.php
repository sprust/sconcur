<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Features\Amqp\Payloads\AckPayload;
use SConcur\Features\Amqp\Payloads\AckPayloadParameters;
use SConcur\Features\Amqp\Payloads\CancelPayload;
use SConcur\Features\Amqp\Payloads\CancelPayloadParameters;
use SConcur\Features\Amqp\Payloads\ConsumePayload;
use SConcur\Features\Amqp\Payloads\ConsumePayloadParameters;
use SConcur\Features\Amqp\Payloads\GetPayload;
use SConcur\Features\Amqp\Payloads\GetPayloadParameters;
use SConcur\Features\Amqp\Payloads\NackPayload;
use SConcur\Features\Amqp\Payloads\NackPayloadParameters;
use SConcur\Features\Amqp\Payloads\QueueBindPayload;
use SConcur\Features\Amqp\Payloads\QueueBindPayloadParameters;
use SConcur\Features\Amqp\Payloads\QueueDeclarePayload;
use SConcur\Features\Amqp\Payloads\QueueDeclarePayloadParameters;
use SConcur\Features\Amqp\Payloads\QueueDeletePayload;
use SConcur\Features\Amqp\Payloads\QueueDeletePayloadParameters;
use SConcur\Features\Amqp\Payloads\QueuePurgePayload;
use SConcur\Features\Amqp\Payloads\QueuePurgePayloadParameters;
use SConcur\Features\Amqp\Payloads\QueueUnbindPayload;
use SConcur\Features\Amqp\Payloads\RecoverPayload;
use SConcur\Features\Amqp\Payloads\RecoverPayloadParameters;
use SConcur\Features\Amqp\Payloads\RejectPayload;
use SConcur\Features\Amqp\Payloads\RejectPayloadParameters;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Amqp\Support\CommandRunner;
use SConcur\Features\Amqp\Support\DeliveredEnvelope;
use SConcur\Features\Amqp\Support\FlagsParser;
use SConcur\Features\Amqp\Support\OrphanedEnvelopeException;
use SConcur\Features\Amqp\Support\TableCodec;
use SConcur\State;
use Throwable;
use WeakReference;

/**
 * A queue — the calque of ext-amqp's AMQPQueue. The name, the flags and the arguments are
 * held here and cost nothing; the methods that talk to the broker are the declaration,
 * the bindings, get(), consume() and the acknowledgements.
 *
 * consume() is where this feature differs from the extension in behaviour rather than in
 * API: instead of holding the PHP process, it suspends its own coroutine while it waits
 * for the next delivery, so one worker can pull several queues at once and serve other
 * work in between. The consumer must be read in the coroutine that opened it — when the
 * coroutine ends, its flow stops and the Go side cancels the consumer.
 */
class AMQPQueue extends AmqpResource
{
    /** The longest queue name the protocol accepts. */
    protected const int MAX_NAME_LENGTH = 255;

    protected AMQPConnection $connection;

    protected AMQPChannel $channel;

    protected ?string $name = null;

    protected ?string $consumerTag = null;

    protected bool $passive = false;

    protected bool $durable = false;

    protected bool $exclusive = false;

    protected bool $autoDelete = true;

    /** @var array<string, mixed> */
    protected array $arguments = [];

    /**
     * The key of the delivery stream opened by consume(); AMQP_JUST_CONSUME reads on.
     */
    protected string $consumeTaskKey = '';

    /**
     * @throws AMQPChannelException if the channel is not open
     */
    public function __construct(AMQPChannel $channel)
    {
        if (!$channel->isConnected()) {
            throw new AMQPChannelException(
                message: 'Could not create queue. No channel available.',
            );
        }

        $this->channel    = $channel;
        $this->connection = $channel->getConnection();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @throws AMQPQueueException if the name is empty or longer than the protocol allows
     */
    public function setName(string $name): void
    {
        $length = strlen($name);

        if ($length < 1 || $length > self::MAX_NAME_LENGTH) {
            throw new AMQPQueueException(
                message: 'Invalid queue name given, must be between 1 and '
                    . self::MAX_NAME_LENGTH . ' characters long.',
            );
        }

        $this->name = $name;
    }

    /** The flags currently set on this queue, as the bit mask ext-amqp uses. */
    public function getFlags(): int
    {
        $flags = AMQP_NOPARAM;

        if ($this->passive) {
            $flags |= AMQP_PASSIVE;
        }

        if ($this->durable) {
            $flags |= AMQP_DURABLE;
        }

        if ($this->exclusive) {
            $flags |= AMQP_EXCLUSIVE;
        }

        if ($this->autoDelete) {
            $flags |= AMQP_AUTODELETE;
        }

        return $flags;
    }

    /**
     * The flags to declare this queue with: AMQP_PASSIVE, AMQP_DURABLE, AMQP_EXCLUSIVE,
     * AMQP_AUTODELETE. Anything else in the mask is ignored — and note that a fresh
     * AMQPQueue is auto-delete, so a mask without AMQP_AUTODELETE turns that off.
     */
    public function setFlags(?int $flags): void
    {
        $this->passive    = FlagsParser::has(flags: $flags, flag: AMQP_PASSIVE);
        $this->durable    = FlagsParser::has(flags: $flags, flag: AMQP_DURABLE);
        $this->exclusive  = FlagsParser::has(flags: $flags, flag: AMQP_EXCLUSIVE);
        $this->autoDelete = FlagsParser::has(flags: $flags, flag: AMQP_AUTODELETE);
    }

    /**
     * @throws AMQPQueueException if no such argument is set
     */
    public function getArgument(string $argumentName): mixed
    {
        if (!array_key_exists($argumentName, $this->arguments)) {
            throw new AMQPQueueException(
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
     * Declares the queue and returns how many messages it holds. An empty name asks the
     * broker to generate one, which is stored on this object; with AMQP_PASSIVE the call
     * only checks that the queue exists.
     *
     * @throws AMQPQueueException if the broker rejects the declaration
     */
    public function declareQueue(): int
    {
        $result = $this->runCommand(
            payload: new QueueDeclarePayload(
                new QueueDeclarePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: (string) $this->name,
                    passive: $this->passive,
                    durable: $this->durable,
                    exclusive: $this->exclusive,
                    autoDelete: $this->autoDelete,
                    noWait: false,
                    arguments: TableCodec::encode($this->arguments),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not declare queue.',
        );

        if (isset($result['na'])) {
            $this->name = (string) $result['na'];
        }

        return isset($result['mc']) ? (int) $result['mc'] : 0;
    }

    /**
     * A synonym of declareQueue().
     *
     * @throws AMQPQueueException if the broker rejects the declaration
     */
    public function declare(): int
    {
        return $this->declareQueue();
    }

    /**
     * Binds the queue to an exchange.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws AMQPQueueException if the broker rejects the binding
     */
    public function bind(string $exchangeName, ?string $routingKey = null, array $arguments = []): void
    {
        $this->runCommand(
            payload: new QueueBindPayload(
                $this->bindParameters(
                    exchangeName: $exchangeName,
                    routingKey: $routingKey,
                    arguments: $arguments,
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not bind queue.',
        );
    }

    /**
     * Removes a binding made by bind().
     *
     * @param array<string, mixed> $arguments
     *
     * @throws AMQPQueueException if the broker rejects the unbinding
     */
    public function unbind(string $exchangeName, ?string $routingKey = null, array $arguments = []): void
    {
        $this->runCommand(
            payload: new QueueUnbindPayload(
                $this->bindParameters(
                    exchangeName: $exchangeName,
                    routingKey: $routingKey,
                    arguments: $arguments,
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not unbind queue.',
        );
    }

    /**
     * Deletes the queue with everything still in it and returns how many messages went
     * with it. AMQP_IFUNUSED and AMQP_IFEMPTY make the broker refuse a queue that is
     * still in use or not empty.
     *
     * @throws AMQPQueueException if the broker rejects the deletion
     */
    public function delete(?int $flags = null): int
    {
        $result = $this->runCommand(
            payload: new QueueDeletePayload(
                new QueueDeletePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: (string) $this->name,
                    ifUnused: FlagsParser::has(flags: $flags, flag: AMQP_IFUNUSED),
                    ifEmpty: FlagsParser::has(flags: $flags, flag: AMQP_IFEMPTY),
                    noWait: FlagsParser::has(flags: $flags, flag: AMQP_NOWAIT),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not delete queue.',
        );

        return isset($result['mc']) ? (int) $result['mc'] : 0;
    }

    /**
     * Removes every message from the queue and returns how many that was.
     *
     * @throws AMQPQueueException if the broker rejects the purge
     */
    public function purge(): int
    {
        $result = $this->runCommand(
            payload: new QueuePurgePayload(
                new QueuePurgePayloadParameters(
                    channelId: $this->channel->internalId,
                    name: (string) $this->name,
                    noWait: false,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not purge queue.',
        );

        return isset($result['mc']) ? (int) $result['mc'] : 0;
    }

    /**
     * The next message of the queue, or null when it is empty — it never waits. With
     * AMQP_AUTOACK the broker considers the message acknowledged as it is sent.
     *
     * @throws AMQPQueueException if the broker rejects the request
     */
    public function get(?int $flags = null): ?AMQPEnvelope
    {
        $result = $this->runCommand(
            payload: new GetPayload(
                new GetPayloadParameters(
                    channelId: $this->channel->internalId,
                    queueName: (string) $this->name,
                    autoAck: FlagsParser::has(flags: $flags, flag: AMQP_AUTOACK),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not get messages from queue.',
        );

        if ($result === []) {
            return null;
        }

        return new DeliveredEnvelope($result);
    }

    /**
     * Consumes the queue, handing every delivery to the callback until it returns false.
     *
     * The callback takes the AMQPEnvelope and, optionally, the AMQPQueue the delivery came
     * from. Unlike the extension, waiting here suspends the coroutine rather than the
     * process, and a consume loop is fed by its own consumer alone — one coroutine per
     * queue replaces ext-amqp's connection-wide dispatch (see docs/amqp.md).
     *
     * Without a callback the consumer is only registered; AMQP_JUST_CONSUME then reads it
     * without opening another one.
     *
     * @throws AMQPQueueException if the broker rejects the consumer or the wait times out
     * @throws AMQPEnvelopeException if a delivery belongs to no known consumer
     */
    public function consume(?callable $callback = null, ?int $flags = null, ?string $consumerTag = null): void
    {
        $justConsume = FlagsParser::has(flags: $flags, flag: AMQP_JUST_CONSUME);

        if ($justConsume && $this->consumeTaskKey === '') {
            throw new AMQPQueueException(
                message: 'Could not consume: AMQP_JUST_CONSUME needs a consumer this queue already opened.',
            );
        }

        if (!$justConsume) {
            $this->openConsumer(flags: $flags, consumerTag: $consumerTag);
        }

        if ($callback === null) {
            return;
        }

        $this->runConsumeLoop($callback);
    }

    /**
     * Acknowledges one delivery, or — with AMQP_MULTIPLE — every delivery up to and
     * including this tag. The acknowledgement must go to the channel the message was
     * delivered on.
     *
     * @throws AMQPQueueException if the broker rejects the acknowledgement
     */
    public function ack(int $deliveryTag, ?int $flags = null): void
    {
        $this->runCommand(
            payload: new AckPayload(
                new AckPayloadParameters(
                    channelId: $this->channel->internalId,
                    deliveryTag: $deliveryTag,
                    multiple: FlagsParser::has(flags: $flags, flag: AMQP_MULTIPLE),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not ack message.',
        );
    }

    /**
     * Refuses one delivery. AMQP_REQUEUE puts the message back into the queue,
     * AMQP_MULTIPLE refuses everything up to and including this tag.
     *
     * @throws AMQPQueueException if the broker rejects the method
     */
    public function nack(int $deliveryTag, ?int $flags = null): void
    {
        $this->runCommand(
            payload: new NackPayload(
                new NackPayloadParameters(
                    channelId: $this->channel->internalId,
                    deliveryTag: $deliveryTag,
                    multiple: FlagsParser::has(flags: $flags, flag: AMQP_MULTIPLE),
                    requeue: FlagsParser::has(flags: $flags, flag: AMQP_REQUEUE),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not nack message.',
        );
    }

    /**
     * Refuses exactly one delivery; AMQP_REQUEUE puts it back into the queue.
     *
     * @throws AMQPQueueException if the broker rejects the method
     */
    public function reject(int $deliveryTag, ?int $flags = null): void
    {
        $this->runCommand(
            payload: new RejectPayload(
                new RejectPayloadParameters(
                    channelId: $this->channel->internalId,
                    deliveryTag: $deliveryTag,
                    requeue: FlagsParser::has(flags: $flags, flag: AMQP_REQUEUE),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not reject message.',
        );
    }

    /**
     * Asks the broker to redeliver everything this consumer has not acknowledged.
     * RabbitMQ only implements $requeue = true.
     *
     * @throws AMQPQueueException if the broker rejects the method
     */
    public function recover(bool $requeue = true): void
    {
        $this->runCommand(
            payload: new RecoverPayload(
                new RecoverPayloadParameters(
                    channelId: $this->channel->internalId,
                    requeue: $requeue,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not redeliver unacknowledged messages.',
        );
    }

    /**
     * Cancels a consumer: the one named, or this queue's latest. A queue that never
     * started consuming sends nothing.
     *
     * @throws AMQPQueueException if the broker rejects the cancellation
     */
    public function cancel(string $consumerTag = ''): void
    {
        $tag = $consumerTag !== '' ? $consumerTag : (string) $this->consumerTag;

        if ($tag === '') {
            return;
        }

        $this->runCommand(
            payload: new CancelPayload(
                new CancelPayloadParameters(
                    channelId: $this->channel->internalId,
                    consumerTag: $tag,
                    noWait: false,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not cancel queue.',
        );

        if ($consumerTag === '' || $consumerTag === $this->consumerTag) {
            $this->consumerTag = null;

            $this->releaseConsumeStream();
        }

        unset($this->channel->internalConsumers[$tag]);
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    public function getConnection(): AMQPConnection
    {
        return $this->connection;
    }

    /** The tag of the consumer this queue opened last, or null once it was cancelled. */
    public function getConsumerTag(): ?string
    {
        return $this->consumerTag;
    }

    /**
     * Registers a consumer for this queue and opens the stream its deliveries arrive on.
     *
     * @throws AMQPQueueException if the broker rejects the consumer
     */
    protected function openConsumer(?int $flags, ?string $consumerTag): void
    {
        $result = $this->runStreamCommand(
            payload: new ConsumePayload(
                new ConsumePayloadParameters(
                    channelId: $this->channel->internalId,
                    queueName: (string) $this->name,
                    consumerTag: $consumerTag ?? '',
                    autoAck: FlagsParser::has(flags: $flags, flag: AMQP_AUTOACK),
                    exclusive: $this->exclusive,
                    noLocal: FlagsParser::has(flags: $flags, flag: AMQP_NOLOCAL),
                    noWait: false,
                    arguments: TableCodec::encode($this->arguments),
                    readTimeoutMs: static::toMilliseconds($this->connection->getReadTimeout()),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this->channel,
            operation: 'Could not start the consumer.',
        );

        $this->consumeTaskKey = $result->key;

        $meta = CommandRunner::decode($result);

        $tag = isset($meta['tg']) ? (string) $meta['tg'] : '';

        $this->consumerTag = $tag;

        $this->channel->internalConsumers[$tag] = WeakReference::create($this);
    }

    /**
     * Pulls deliveries one by one, suspending the coroutine between them, and stops when
     * the callback returns false or the stream ends (the consumer was cancelled, the
     * channel died, or the read timeout expired).
     *
     * @throws AMQPQueueException if the stream fails
     * @throws AMQPEnvelopeException if a delivery belongs to no known consumer
     */
    protected function runConsumeLoop(callable $callback): void
    {
        while (true) {
            try {
                $result = $this->nextStream(
                    taskKey: $this->consumeTaskKey,
                    exceptionClass: AMQPQueueException::class,
                    channel: $this->channel,
                );
            } catch (AMQPException $exception) {
                // The stream is gone with the failure (a read timeout, a dead channel), so
                // the key it was pulled by leads nowhere: forget it, or AMQP_JUST_CONSUME
                // would try to read a consumer that no longer exists.
                $this->releaseConsumeStream();

                throw $exception;
            }

            if (!$result->hasNext) {
                $this->releaseConsumeStream();

                return;
            }

            $envelope = new DeliveredEnvelope(CommandRunner::decode($result));

            $queue = $this->resolveConsumer((string) $envelope->getConsumerTag());

            if ($queue === null) {
                throw new OrphanedEnvelopeException(message: 'Orphaned envelope', envelope: $envelope);
            }

            if ($callback($envelope, $queue) === false) {
                return;
            }
        }
    }

    /**
     * The queue a delivery belongs to, found by its consumer tag among the consumers of
     * this channel. With a stream per consumer that is this queue; the lookup is what makes
     * the callback's second argument mean what it means in ext-amqp.
     */
    protected function resolveConsumer(string $consumerTag): ?AMQPQueue
    {
        $reference = $this->channel->internalConsumers[$consumerTag] ?? null;

        return $reference?->get();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function bindParameters(
        string $exchangeName,
        ?string $routingKey,
        array $arguments,
    ): QueueBindPayloadParameters {
        return new QueueBindPayloadParameters(
            channelId: $this->channel->internalId,
            queueName: (string) $this->name,
            exchangeName: $exchangeName,
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

    /**
     * Gives the delivery stream back.
     *
     * A consume() that ends early — the callback returned false — leaves the stream open on
     * purpose: AMQP_JUST_CONSUME reads it on. Outside a coroutine that stream owns a flow
     * of its own, and nothing else will ever release it, so whoever is done with the
     * consumer has to: cancelling it, reading it to its end, or dropping the queue object.
     * Inside a coroutine this is a no-op — the coroutine's own flow owns the stream.
     */
    protected function releaseConsumeStream(): void
    {
        if ($this->consumeTaskKey === '') {
            return;
        }

        $taskKey = $this->consumeTaskKey;

        $this->consumeTaskKey = '';

        State::releaseSyncTaskFlow($taskKey);
    }

    /**
     * A queue an application dropped while its consumer was still open releases the stream
     * here; the extension has no destructor because its consumer costs it nothing but a
     * tag.
     */
    public function __destruct()
    {
        try {
            $this->releaseConsumeStream();
        } catch (Throwable) {
            // Shutting down: there is nobody left to report this to.
        }
    }
}
