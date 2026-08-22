<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use Generator;
use SConcur\Connection\Extension;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Exceptions\Amqp\ExchangeException;
use SConcur\Exceptions\Amqp\PublishConfirmTimeoutException;
use SConcur\Exceptions\Amqp\PublishNackedException;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Payloads\AckPayload;
use SConcur\Features\Amqp\Payloads\AckPayloadParameters;
use SConcur\Features\Amqp\Payloads\CancelPayload;
use SConcur\Features\Amqp\Payloads\CancelPayloadParameters;
use SConcur\Features\Amqp\Payloads\ChannelClosePayload;
use SConcur\Features\Amqp\Payloads\ChannelOpenPayload;
use SConcur\Features\Amqp\Payloads\ChannelOpenPayloadParameters;
use SConcur\Features\Amqp\Payloads\ChannelPayloadParameters;
use SConcur\Features\Amqp\Payloads\ConfirmSelectPayload;
use SConcur\Features\Amqp\Payloads\ConfirmSelectPayloadParameters;
use SConcur\Features\Amqp\Payloads\ConfirmWaitPayload;
use SConcur\Features\Amqp\Payloads\ConsumePayload;
use SConcur\Features\Amqp\Payloads\ConsumePayloadParameters;
use SConcur\Features\Amqp\Payloads\GetPayload;
use SConcur\Features\Amqp\Payloads\GetPayloadParameters;
use SConcur\Features\Amqp\Payloads\NackPayload;
use SConcur\Features\Amqp\Payloads\NackPayloadParameters;
use SConcur\Features\Amqp\Payloads\PublishPayload;
use SConcur\Features\Amqp\Payloads\PublishPayloadParameters;
use SConcur\Features\Amqp\Payloads\QosPayload;
use SConcur\Features\Amqp\Payloads\QosPayloadParameters;
use SConcur\Features\Amqp\Payloads\RejectPayload;
use SConcur\Features\Amqp\Payloads\RejectPayloadParameters;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Amqp\Support\CommandRunner;
use SConcur\Features\Amqp\Support\PropertiesCodec;
use SConcur\Features\Amqp\Support\TableCodec;
use SConcur\Scheduler\Scheduler;
use SConcur\State;
use Throwable;
use WeakReference;

/**
 * A channel: the conversation a coroutine has with the broker.
 *
 * Every command AMQP 0-9-1 has is a channel command, and that is where they live here.
 * Queue and Exchange are named handles that pass their name along — the calque put the
 * same commands on all three and made a queue carry an acknowledgement that belongs to the
 * channel it arrived on.
 *
 * A channel is never shared between coroutines. The commands of one channel are
 * serialized, so two coroutines on one channel is two coroutines in a queue.
 *
 * Any command here can fail at connection level rather than at channel level — the broker
 * decides which by the reply code it answers with — so a ConnectionException may come out
 * of a call whose @throws names only the channel-level one.
 */
class Channel extends AmqpResource
{
    /** How many deliveries the broker may have in flight per consumer unless told otherwise. */
    public const int DEFAULT_PREFETCH_COUNT = 3;

    protected const int MAX_PREFETCH_COUNT = 65535;

    protected const int MAX_PREFETCH_SIZE_BYTES = 4294967295;

    protected Connection $connection;

    /** The channel number the broker assigned. */
    protected int $channelNumber = 0;

    /** Whether confirm mode has been turned on; it cannot be turned back off. */
    protected bool $confirming = false;

    /**
     * The weak reference every Delivery of this channel is handed, built once. A channel's
     * reference to itself never changes, and building one per delivery would put an
     * allocation on the hottest path the feature has.
     *
     * @var WeakReference<self>|null
     */
    protected ?WeakReference $selfReference = null;

    /**
     * Opens a channel on a connection. Prefer `Connection::channel()`, which opens the
     * connection first when it is not open yet.
     *
     * @throws ConnectionException if the connection is not open or the broker refuses
     */
    public function __construct(
        Connection $connection,
        int $prefetchCount = self::DEFAULT_PREFETCH_COUNT,
        int $prefetchSize = 0,
    ) {
        $this->connection = $connection;

        if (!$connection->isOpen()) {
            throw new ConnectionException(message: 'Could not open a channel. No connection available.');
        }

        static::assertPrefetch(count: $prefetchCount, size: $prefetchSize);

        $result = $this->runCommand(
            payload: new ChannelOpenPayload(
                new ChannelOpenPayloadParameters(
                    connectionId: $connection->internalId,
                    prefetchSizeBytes: $prefetchSize,
                    prefetchCount: $prefetchCount,
                    globalPrefetchSizeBytes: 0,
                    globalPrefetchCount: 0,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: ConnectionException::class,
        );

        $this->internalId    = isset($result['chid']) ? (string) $result['chid'] : '';
        $this->channelNumber = isset($result['no']) ? (int) $result['no'] : 0;
        $this->internalOpen  = true;

        // The connection keeps a weak reference so close() can mark this channel closed:
        // releasing the handle is what actually closes it on the Go side.
        $connection->internalChannels[$this->internalId] = WeakReference::create($this);
    }

    /** The connection this channel was opened on. */
    public function connection(): Connection
    {
        return $this->connection;
    }

    /** The channel number on the connection. */
    public function id(): int
    {
        return $this->channelNumber;
    }

    /**
     * Whether the channel is usable. It reports what happened on this side and does not
     * probe the broker.
     */
    public function isOpen(): bool
    {
        return $this->internalOpen && $this->connection->isOpen();
    }

    /**
     * Closes the channel. Idempotent and best-effort: a channel the broker already dropped
     * needs no closing.
     */
    public function close(): void
    {
        if (!$this->internalOpen) {
            return;
        }

        // Nothing here can wait for the broker: this coroutine has been let go of, and an
        // awaited close would suspend a fiber nothing will resume. Hand the channel back
        // and be done.
        if (!Scheduler::get()->canAwait()) {
            $this->closeDetached();

            return;
        }

        $channelId = $this->internalId;

        $this->internalOpen = false;
        $this->internalId   = '';

        // Dropped from the connection's registry with the id it was filed under: the entry
        // is a weak reference, but the key is a string, and a worker that opens a channel
        // per request would otherwise grow that array for the life of the connection.
        unset($this->connection->internalChannels[$channelId]);

        // The command runs unguarded: this object has just marked itself closed, and the
        // guard exists to stop calls that would reach a channel the broker took away —
        // which is the opposite of releasing one this object still owns.
        try {
            $this->runCommand(
                payload: new ChannelClosePayload(
                    new ChannelPayloadParameters(
                        channelId: $channelId,
                        timeoutMs: $this->timeoutMs(),
                    ),
                ),
                exceptionClass: ChannelException::class,
            );
        } catch (AmqpException) {
            // The channel is already gone — nothing to close.
        }
    }

    /**
     * How much the broker may push before it is acknowledged: a message count, a window in
     * octets, or both. With `global` the limits apply to the whole channel instead of to
     * each consumer on it separately.
     *
     * @throws ChannelException if the broker rejects the settings
     * @throws ConnectionException if it refuses them at connection level, as RabbitMQ does
     *                             for a prefetch size — it has never implemented one
     */
    public function prefetch(int $count, int $size = 0, bool $global = false): void
    {
        static::assertPrefetch(count: $count, size: $size);

        $this->runCommand(
            payload: new QosPayload(
                new QosPayloadParameters(
                    channelId: $this->internalId,
                    prefetchSizeBytes: $size,
                    prefetchCount: $count,
                    global: $global,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: ChannelException::class,
            channel: $this,
            operation: 'Could not set the prefetch.',
        );
    }

    /** A handle on a queue of this channel. Costs nothing and talks to nobody. */
    public function queue(string $name): Queue
    {
        return new Queue(channel: $this, name: $name);
    }

    /**
     * A handle on an exchange of this channel. The empty name is the default exchange,
     * which routes by queue name.
     */
    public function exchange(string $name): Exchange
    {
        return new Exchange(channel: $this, name: $name);
    }

    /**
     * Publishes a message. A plain string is published as a body with no properties.
     *
     * The broker sends no reply to a publish, so this returns as soon as the message is
     * handed over — it says nothing about the broker having stored it. `publishConfirmed()`
     * is the call that waits for that.
     *
     * @param bool $mandatory ask the broker to send the message back when it routes
     *                        nowhere, instead of dropping it silently. Only
     *                        `publishConfirmed()` waits long enough to see the return
     *
     * @throws ExchangeException if the message could not be handed to the broker
     */
    public function publish(
        Message|string $message,
        string $exchange = '',
        string $routingKey = '',
        bool $mandatory = false,
    ): void {
        $message = is_string($message) ? new Message($message) : $message;

        $this->runCommand(
            payload: new PublishPayload(
                new PublishPayloadParameters(
                    channelId: $this->internalId,
                    exchangeName: $exchange,
                    routingKey: $routingKey,
                    mandatory: $mandatory,
                    // RabbitMQ has never implemented basic.publish's immediate flag; it
                    // closes the channel on one that sets it.
                    immediate: false,
                    body: $message->body,
                    properties: PropertiesCodec::encode($message),
                    timeoutMs: static::toMilliseconds($this->connection->options->writeTimeout),
                ),
            ),
            exceptionClass: ExchangeException::class,
            channel: $this,
            operation: 'Could not publish.',
        );
    }

    /**
     * Puts the channel into publisher-confirm mode: from here on the broker reports every
     * published message as stored or refused. It cannot be turned back off — AMQP has no
     * method for that — and `publishConfirmed()` turns it on by itself.
     *
     * @throws ChannelException if the broker rejects it
     */
    public function enableConfirms(): void
    {
        if ($this->confirming) {
            return;
        }

        $this->runCommand(
            payload: new ConfirmSelectPayload(
                new ConfirmSelectPayloadParameters(
                    channelId: $this->internalId,
                    noWait: false,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: ChannelException::class,
            channel: $this,
            operation: 'Could not enter confirm mode.',
        );

        $this->confirming = true;
    }

    /**
     * Publishes a message and waits until the broker has taken responsibility for it.
     *
     * This is what the calque needed a pair of callbacks and a separate wait for: the
     * extension cannot suspend, so a confirm had to arrive somewhere other than at the
     * publish. Here the coroutine waits, and publishing a batch concurrently is a WaitGroup
     * around this call rather than an API of its own.
     *
     * Published as mandatory, so a message that routes nowhere comes back as an
     * UnroutableMessageException rather than disappearing.
     *
     * @param float $timeout seconds to wait; 0 waits until the broker answers
     *
     * @throws PublishNackedException if the broker refused to store the message
     * @throws UnroutableMessageException if it reached no queue
     * @throws PublishConfirmTimeoutException if the broker did not answer in time
     */
    public function publishConfirmed(
        Message|string $message,
        string $exchange = '',
        string $routingKey = '',
        float $timeout = 0.0,
        bool $mandatory = true,
    ): void {
        $this->enableConfirms();

        $this->publish(message: $message, exchange: $exchange, routingKey: $routingKey, mandatory: $mandatory);

        $this->waitForConfirms($timeout);
    }

    /**
     * Waits until every message published on this channel since the last wait has been
     * confirmed, and fails on the first one the broker did not take.
     *
     * @param float $timeout seconds to wait; 0 waits until the broker answers
     *
     * @throws PublishNackedException if the broker refused to store a message
     * @throws UnroutableMessageException if a message reached no queue
     * @throws PublishConfirmTimeoutException if the broker did not answer in time
     */
    public function waitForConfirms(float $timeout = 0.0): void
    {
        if (!$this->confirming) {
            throw new ChannelException(
                message: 'Could not wait for the publisher confirms: the channel is not in confirm mode.',
            );
        }

        $result = $this->runCommand(
            payload: new ConfirmWaitPayload(
                new ChannelPayloadParameters(
                    channelId: $this->internalId,
                    timeoutMs: static::toMilliseconds($timeout),
                ),
            ),
            // A wait that runs out of time is a command failure with no reply code, so this
            // is the class it is raised as; a channel that died on the way is still a
            // ChannelException, which CommandRunner decides on its own.
            exceptionClass: PublishConfirmTimeoutException::class,
            channel: $this,
            operation: 'Could not wait for the publisher confirms.',
        );

        // The return comes first on the wire and carries more: a message the broker could
        // not route is also acknowledged, so reading the confirmations first would report
        // a success for a message that reached nothing.
        $this->failOnReturns(is_array($result['rt'] ?? null) ? $result['rt'] : []);
        $this->failOnNacks(is_array($result['cf'] ?? null) ? $result['cf'] : []);
    }

    /**
     * Acknowledges a delivery by tag. Prefer `Delivery::ack()`, which knows its own tag and
     * refuses to settle twice.
     *
     * @throws QueueException if the broker rejects the acknowledgement
     */
    public function ack(int $deliveryTag, bool $multiple = false): void
    {
        $this->runCommand(
            payload: new AckPayload(
                new AckPayloadParameters(
                    channelId: $this->internalId,
                    deliveryTag: $deliveryTag,
                    multiple: $multiple,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: QueueException::class,
            channel: $this,
            operation: 'Could not ack the delivery.',
        );
    }

    /**
     * Refuses a delivery by tag. Prefer `Delivery::nack()`.
     *
     * @throws QueueException if the broker rejects the method
     */
    public function nack(int $deliveryTag, bool $requeue = true, bool $multiple = false): void
    {
        $this->runCommand(
            payload: new NackPayload(
                new NackPayloadParameters(
                    channelId: $this->internalId,
                    deliveryTag: $deliveryTag,
                    multiple: $multiple,
                    requeue: $requeue,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: QueueException::class,
            channel: $this,
            operation: 'Could not nack the delivery.',
        );
    }

    /**
     * Rejects exactly one delivery by tag. Prefer `Delivery::reject()`.
     *
     * @throws QueueException if the broker rejects the method
     */
    public function reject(int $deliveryTag, bool $requeue = false): void
    {
        $this->runCommand(
            payload: new RejectPayload(
                new RejectPayloadParameters(
                    channelId: $this->internalId,
                    deliveryTag: $deliveryTag,
                    requeue: $requeue,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: QueueException::class,
            channel: $this,
            operation: 'Could not reject the delivery.',
        );
    }

    /**
     * One message from a queue, or null when it is empty. It never waits — this is
     * basic.get, the polling form, and a worker that reads a queue continuously wants
     * `consume()` instead.
     *
     * @throws QueueException if the broker rejects the request
     */
    public function get(string $queueName, bool $autoAck = false): ?Delivery
    {
        $result = $this->runCommand(
            payload: new GetPayload(
                new GetPayloadParameters(
                    channelId: $this->internalId,
                    queueName: $queueName,
                    autoAck: $autoAck,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: QueueException::class,
            channel: $this,
            operation: 'Could not get a message from the queue.',
        );

        if ($result === []) {
            return null;
        }

        return $this->deliveryFrom(delivery: $result, autoAck: $autoAck);
    }

    /**
     * Consumes a queue: every delivery is yielded in turn, and the coroutine is suspended
     * between them.
     *
     * The generator owns the consumer. Leaving the loop — a `break`, a `return`, an
     * exception, or the coroutine being unwound — cancels it and gives the delivery stream
     * back; the calque needed a registry on the channel for that, because a callback has no
     * scope to end.
     *
     * The loop ends quietly only when this coroutine's flow is stopped. Everything else
     * that takes the consumer away raises: the broker cancelling it (the queue was
     * deleted, a node failed over), the channel dying, and the connection's read timeout
     * passing with nothing delivered — the broker still holds the consumer there, so a
     * worker that read the silence as "the queue is closed" would stop reading a queue
     * that is merely idle.
     *
     * @param array<string, mixed> $arguments consumer arguments, such as `x-priority`
     *
     * @return Generator<int, Delivery>
     *
     * @throws QueueException if the broker rejects the consumer or the stream fails
     */
    public function consume(
        string $queueName,
        bool $autoAck = false,
        string $consumerTag = '',
        bool $exclusive = false,
        bool $noLocal = false,
        array $arguments = [],
    ): Generator {
        $result = $this->runStreamCommand(
            payload: new ConsumePayload(
                new ConsumePayloadParameters(
                    channelId: $this->internalId,
                    queueName: $queueName,
                    consumerTag: $consumerTag,
                    autoAck: $autoAck,
                    exclusive: $exclusive,
                    noLocal: $noLocal,
                    noWait: false,
                    arguments: TableCodec::encode($arguments),
                    readTimeoutMs: static::toMilliseconds($this->connection->options->readTimeout),
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: QueueException::class,
            channel: $this,
            operation: 'Could not start the consumer.',
        );

        $taskKey = $result->key;

        $meta = CommandRunner::decode($result);

        $tag = isset($meta['tg']) ? (string) $meta['tg'] : '';

        $ended = false;

        try {
            while (true) {
                $next = $this->nextStream(taskKey: $taskKey, exceptionClass: QueueException::class, channel: $this);

                if (!$next->hasNext) {
                    $ended = true;

                    return;
                }

                yield $this->deliveryFrom(delivery: CommandRunner::decode($next), autoAck: $autoAck);
            }
        } finally {
            State::releaseSyncTaskFlow($taskKey);

            if (!$ended) {
                $this->cancelConsumer(consumerTag: $tag);
            }
        }
    }

    /**
     * Gives the channel back without waiting for the broker to answer.
     *
     * For a coroutine that was unwound. Its flow is gone, so an ordinary close would fail;
     * and waiting for the garbage collector instead is not good enough, because the channel
     * stays open until it runs — and every delivery that channel took but never
     * acknowledged stays owed to the broker with it, for up to the broker's consumer
     * timeout. close() falls back to this when the coroutine can no longer wait, and the
     * destructor uses it because it has nothing to wait on either.
     */
    protected function closeDetached(): void
    {
        // Keyed on the handle and not on the open flag, the way Connection::close() is: a
        // command that failed marks the channel closed on this side without releasing
        // anything on the other, and a channel released by nobody stays open on the broker
        // holding whatever it never acknowledged.
        if ($this->internalId === '') {
            return;
        }

        $channelId = $this->internalId;

        $this->internalOpen = false;
        $this->internalId   = '';

        unset($this->connection->internalChannels[$channelId]);

        try {
            Extension::get()->push(
                flowKey: '',
                payload: new ChannelClosePayload(
                    new ChannelPayloadParameters(
                        channelId: $channelId,
                        timeoutMs: $this->timeoutMs(),
                    ),
                ),
            );
        } catch (Throwable) {
            // The extension is already gone (the process is shutting down), and with it
            // every channel it held.
        }
    }

    /**
     * The deadline of one broker method on this channel, in milliseconds — the connection's
     * rpc timeout.
     */
    protected function timeoutMs(): int
    {
        return static::toMilliseconds($this->connection->options->rpcTimeout);
    }

    /**
     * @param array<mixed> $delivery
     * @param bool         $autoAck  whether the consumer or the get asked the broker to
     *                               treat the message as answered on delivery
     */
    protected function deliveryFrom(array $delivery, bool $autoAck): Delivery
    {
        /** @var array<mixed> $rawProperties */
        $rawProperties = is_array($delivery['ps'] ?? null) ? $delivery['ps'] : [];

        return new Delivery(
            body: isset($delivery['bd']) ? (string) $delivery['bd'] : '',
            routingKey: isset($delivery['rk']) ? (string) $delivery['rk'] : '',
            exchange: isset($delivery['en']) ? (string) $delivery['en'] : '',
            // A message pulled with basic.get belongs to no consumer, and reports an empty
            // tag rather than a made-up one.
            consumerTag: isset($delivery['tg']) ? (string) $delivery['tg'] : '',
            deliveryTag: isset($delivery['dt']) ? (int) $delivery['dt'] : 0,
            redelivered: (bool) ($delivery['rd'] ?? false),
            properties: PropertiesCodec::decode($rawProperties),
            channel: $this->selfReference ??= WeakReference::create($this),
            settled: $autoAck,
        );
    }

    /**
     * Ends a consumer that its loop left before the stream did.
     *
     * Awaited whenever it can be, because the next consume on this channel must not race
     * with it: a cancel still in flight leaves the old consumer registered, the broker may
     * hand the message to the stream nobody reads any more, and the new consumer waits for
     * a delivery that was already given away.
     *
     * Detached is for the one case where waiting is impossible — a coroutine unwound by
     * WaitGroup::stop or Scheduler::shutdown, whose fiber the scheduler has already
     * detached. The broker still gets the method, and it is the last thing this channel
     * does before the object releases it.
     */
    protected function cancelConsumer(string $consumerTag): void
    {
        if ($consumerTag === '' || !$this->internalOpen) {
            return;
        }

        // Waiting for the broker to confirm the cancel is what keeps the next consume on
        // this channel honest: a cancel still in flight leaves the old consumer
        // registered, the broker may hand the message to the stream nobody reads any more,
        // and the new consumer waits for a delivery that was already given away.
        $detached = !Scheduler::get()->canAwait();

        $payload = new CancelPayload(
            new CancelPayloadParameters(
                channelId: $this->internalId,
                consumerTag: $consumerTag,
                noWait: $detached,
                timeoutMs: $this->timeoutMs(),
            ),
        );

        try {
            if ($detached) {
                Extension::get()->push(flowKey: '', payload: $payload);

                return;
            }

            $this->runCommand(
                payload: $payload,
                exceptionClass: QueueException::class,
                channel: $this,
                operation: 'Could not cancel the consumer.',
            );
        } catch (AmqpException) {
            // The channel or the consumer is already gone — there is nothing left to
            // cancel, and a teardown is no place to fail.
        }
    }

    /**
     * @param array<mixed> $returns
     *
     * @throws UnroutableMessageException
     */
    protected function failOnReturns(array $returns): void
    {
        foreach ($returns as $returned) {
            if (!is_array($returned)) {
                continue;
            }

            /** @var array<mixed> $rawProperties */
            $rawProperties = is_array($returned['ps'] ?? null) ? $returned['ps'] : [];

            $replyCode = isset($returned['rc']) ? (int) $returned['rc'] : 0;
            $replyText = isset($returned['rx']) ? (string) $returned['rx'] : '';

            throw new UnroutableMessageException(
                message: trim("The broker returned the message as unroutable: $replyCode $replyText"),
                returnedMessage: PropertiesCodec::messageFrom(
                    body: isset($returned['bd']) ? (string) $returned['bd'] : '',
                    properties: $rawProperties,
                ),
                exchange: isset($returned['en']) ? (string) $returned['en'] : '',
                routingKey: isset($returned['rk']) ? (string) $returned['rk'] : '',
                replyCode: $replyCode,
            );
        }
    }

    /**
     * @param array<mixed> $confirmations
     *
     * @throws PublishNackedException
     */
    protected function failOnNacks(array $confirmations): void
    {
        foreach ($confirmations as $confirmation) {
            if (!is_array($confirmation) || (bool) ($confirmation['ak'] ?? false)) {
                continue;
            }

            $deliveryTag = isset($confirmation['dt']) ? (int) $confirmation['dt'] : 0;

            throw new PublishNackedException(
                message: "The broker refused to store the message published as delivery tag $deliveryTag.",
            );
        }
    }

    /**
     * @throws ConnectionException if a limit is outside the range the protocol allows
     */
    protected static function assertPrefetch(int $count, int $size): void
    {
        if ($count < 0 || $count > self::MAX_PREFETCH_COUNT) {
            throw new ConnectionException(
                message: "Parameter 'prefetchCount' must be between 0 and " . self::MAX_PREFETCH_COUNT . '.',
            );
        }

        if ($size < 0 || $size > self::MAX_PREFETCH_SIZE_BYTES) {
            throw new ConnectionException(
                message: "Parameter 'prefetchSize' must be between 0 and " . self::MAX_PREFETCH_SIZE_BYTES . '.',
            );
        }
    }

    /**
     * A channel an application dropped without closing is closed best-effort here, the same
     * detached way — a destructor has nothing to wait on either.
     */
    public function __destruct()
    {
        $this->closeDetached();
    }
}
