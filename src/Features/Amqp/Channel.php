<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use Generator;
use SConcur\Connection\Extension;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Exceptions\Amqp\ExchangeException;
use SConcur\Exceptions\Amqp\InvalidPrefetchException;
use SConcur\Exceptions\Amqp\InvalidRetryException;
use SConcur\Exceptions\Amqp\PublishConfirmTimeoutException;
use SConcur\Exceptions\Amqp\PublishNackedException;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Payloads\AmqpPayload;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Amqp\Support\DeliveryCodec;
use SConcur\Features\Amqp\Support\PropertiesCodec;
use SConcur\Features\Amqp\Support\TableCodec;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\State;
use Throwable;
use WeakReference;

/**
 * A channel: the conversation a coroutine has with the broker. Queue and Exchange are named
 * handles over the same commands.
 *
 * Never share one between coroutines — the commands of a channel are serialized, so two
 * coroutines on one channel is two coroutines in a queue.
 *
 * Any command here can fail at connection level instead of channel level — the broker
 * decides by the reply code — so a ConnectionException may come out of a call that reads
 * like a channel-level one.
 */
class Channel extends AmqpResource
{
    /** How many deliveries the broker may have in flight per consumer unless told otherwise. */
    public const int DEFAULT_PREFETCH_COUNT = 3;

    protected const int MAX_PREFETCH_COUNT = 65535;

    protected const int MAX_PREFETCH_SIZE_BYTES = 4294967295;

    protected Connection $connection;

    protected int $channelNumber = 0;

    /** Confirm mode cannot be turned back off — AMQP has no method for it. */
    protected bool $confirming = false;

    /**
     * Prefer `Connection::channel()`, which opens the connection first when it is not open.
     */
    public function __construct(
        Connection $connection,
        int $prefetchCount = self::DEFAULT_PREFETCH_COUNT,
        int $prefetchSizeBytes = 0,
    ) {
        $this->connection = $connection;

        if (!$connection->isOpen()) {
            throw new ConnectionException(message: 'Could not open a channel. No connection available.');
        }

        static::assertPrefetch(
            count: $prefetchCount,
            sizeBytes: $prefetchSizeBytes,
        );

        $result = $this->runCommand(
            command: AmqpCommandEnum::ChannelOpen,
            data: [
                'cid' => $connection->internalId,
                'sz'  => $prefetchSizeBytes,
                'ct'  => $prefetchCount,
                'gsz' => 0,
                'gct' => 0,
                'to'  => $connection->rpcTimeoutMs(),
            ],
            exceptionClass: ConnectionException::class,
        );

        $this->internalId    = isset($result['chid']) ? (string) $result['chid'] : '';
        $this->channelNumber = isset($result['no']) ? (int) $result['no'] : 0;
        $this->internalOpen  = true;

        // Releasing the connection handle is what closes this channel on the Go side, so
        // the connection needs a way back to mark it closed here.
        $connection->internalChannels[$this->internalId] = WeakReference::create($this);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /** The channel's number on its connection. */
    public function id(): int
    {
        return $this->channelNumber;
    }

    /** Reports what happened on this side; it does not probe the broker. */
    public function isOpen(): bool
    {
        return $this->internalOpen && $this->connection->isOpen();
    }

    /**
     * Runs one command on this channel and answers its decoded result.
     *
     * @internal how Queue and Exchange reach the broker, so they own nothing on the Go side
     *           and cannot skip the bookkeeping a failure implies.
     *
     * @param array<string, mixed>        $data           `chid` and `to` are filled in here
     * @param class-string<AmqpException> $exceptionClass
     *
     * @return array<mixed>
     */
    public function run(
        AmqpCommandEnum $command,
        array $data,
        string $exceptionClass,
        string $operation = '',
    ): array {
        $data['chid'] = $this->internalId;
        $data['to'] ??= $this->connection->rpcTimeoutMs();

        return $this->runCommand(
            command: $command,
            data: $data,
            exceptionClass: $exceptionClass,
            channel: $this,
            operation: $operation,
        );
    }

    /** Idempotent and best-effort: a channel the broker already dropped needs no closing. */
    public function close(): void
    {
        if (!$this->internalOpen) {
            return;
        }

        // An awaited close on a coroutine the runtime has let go of would suspend a fiber
        // nothing will resume.
        if (!FeatureExecutor::canAwait()) {
            $this->closeDetached();

            return;
        }

        $channelId = $this->releaseHandle();

        // Unguarded on purpose: the guard stops calls to a channel the broker took away,
        // which is the opposite of releasing one this object still owns.
        try {
            $this->runCommand(
                command: AmqpCommandEnum::ChannelClose,
                data: [
                    'chid' => $channelId,
                    'to'   => $this->connection->rpcTimeoutMs(),
                ],
                exceptionClass: ChannelException::class,
            );
        } catch (AmqpException) {
            // The channel is already gone — nothing to close.
        }
    }

    /**
     * How much the broker may push before it is acknowledged. With `global` the limits
     * apply to the whole channel instead of to each consumer on it separately.
     */
    public function prefetch(int $count, int $sizeBytes = 0, bool $global = false): void
    {
        static::assertPrefetch(
            count: $count,
            sizeBytes: $sizeBytes,
        );

        $this->run(
            command: AmqpCommandEnum::Qos,
            data: [
                'sz' => $sizeBytes,
                'ct' => $count,
                'gl' => $global,
            ],
            exceptionClass: ChannelException::class,
            operation: 'Could not set the prefetch.',
        );
    }

    /** A handle; it talks to nobody. */
    public function queue(string $name): Queue
    {
        return new Queue(
            channel: $this,
            name: $name,
        );
    }

    /** A handle; the empty name is the default exchange, which routes by queue name. */
    public function exchange(string $name): Exchange
    {
        return new Exchange(
            channel: $this,
            name: $name,
        );
    }

    /**
     * Publishes a message; a plain string becomes a body with no properties.
     *
     * basic.publish carries no reply, so this returns once the message is handed over and
     * says nothing about the broker having stored it — `publishConfirmed()` waits for that.
     *
     * @param bool $mandatory ask the broker to send an unroutable message back instead of
     *                        dropping it. Only `publishConfirmed()` waits to see the return
     */
    public function publish(
        Message|string $message,
        string $exchange = '',
        string $routingKey = '',
        bool $mandatory = false,
    ): void {
        $message = is_string($message) ? new Message($message) : $message;

        $this->run(
            command: AmqpCommandEnum::Publish,
            data: [
                'en' => $exchange,
                'rk' => $routingKey,
                'ma' => $mandatory,
                // RabbitMQ closes the channel on an immediate flag; it never implemented one.
                'im' => false,
                'bd' => $message->body,
                'ps' => PropertiesCodec::encode($message),
                'to' => $this->connection->writeTimeoutMs(),
            ],
            exceptionClass: ExchangeException::class,
            operation: 'Could not publish.',
        );
    }

    /**
     * From here on the broker reports every published message as stored or refused.
     * `publishConfirmed()` turns it on by itself.
     */
    public function enableConfirms(): void
    {
        if ($this->confirming) {
            return;
        }

        $this->run(
            command: AmqpCommandEnum::ConfirmSelect,
            data: ['nw' => false],
            exceptionClass: ChannelException::class,
            operation: 'Could not enter confirm mode.',
        );

        $this->confirming = true;
    }

    /**
     * Publishes and waits until the broker has taken responsibility for the message.
     * Mandatory by default, so one that routes nowhere raises instead of disappearing.
     *
     * A batch is a WaitGroup around this call rather than an API of its own.
     *
     * `retries` covers the three failures the broker answers with — a nack, a message that
     * routed nowhere, and a confirm that never came. It does not cover a dead channel or a
     * dead connection: the handle is gone by then and no number of attempts brings it back.
     * A retried confirm timeout can duplicate the message, which is the at-least-once AMQP
     * offers either way.
     *
     * @param float       $timeoutSeconds     0 waits until the broker answers
     * @param int         $retries            how many further attempts a refused publish
     *                                        may have
     * @param list<float> $retryDelaysSeconds the wait after each failure, by attempt number;
     *                                        an attempt past the end takes the last entry,
     *                                        and an empty schedule waits not at all
     */
    public function publishConfirmed(
        Message|string $message,
        string $exchange = '',
        string $routingKey = '',
        float $timeoutSeconds = 0.0,
        bool $mandatory = true,
        int $retries = 0,
        array $retryDelaysSeconds = [],
    ): void {
        if ($retries < 0) {
            throw new InvalidRetryException(
                message: "A publish cannot be retried a negative number of times, got $retries.",
            );
        }

        $schedule = new RetrySchedule($retryDelaysSeconds);

        $this->enableConfirms();

        $attempt = 0;

        while (true) {
            try {
                $this->publish(
                    message: $message,
                    exchange: $exchange,
                    routingKey: $routingKey,
                    mandatory: $mandatory,
                );

                $this->waitForConfirms($timeoutSeconds);

                return;
            } catch (PublishNackedException | UnroutableMessageException | PublishConfirmTimeoutException $failure) {
                // Only the three the broker answered with. A channel or a connection that
                // died raises past this on purpose: the handle is gone, every further
                // attempt fails the same way, and retrying would spend the whole schedule
                // to arrive at the exception the first attempt already had.
                if ($attempt >= $retries) {
                    throw $failure;
                }

                ++$attempt;
            }

            $delaySeconds = $schedule->delaySecondsFor($attempt);

            if ($delaySeconds > 0) {
                Sleeper::usleep(microseconds: (int) round($delaySeconds * 1_000_000));
            }
        }
    }

    /**
     * Waits for every message published since the last wait, failing on the first one the
     * broker did not take.
     *
     * @param float $timeoutSeconds 0 waits until the broker answers
     */
    public function waitForConfirms(float $timeoutSeconds = 0.0): void
    {
        if (!$this->confirming) {
            throw new ChannelException(
                message: 'Could not wait for the publisher confirms: the channel is not in confirm mode.',
            );
        }

        $result = $this->run(
            command: AmqpCommandEnum::ConfirmWait,
            data: ['to' => static::toMilliseconds($timeoutSeconds)],
            // A wait that ran out of time carries no reply code, so it becomes this class;
            // a channel that died on the way is still a ChannelException (see translate).
            exceptionClass: PublishConfirmTimeoutException::class,
            operation: 'Could not wait for the publisher confirms.',
        );

        // Returns first: an unroutable message is acknowledged too, so reading the
        // confirmations first would report success for a message that reached nothing.
        DeliveryCodec::failOnReturns(is_array($result['rt'] ?? null) ? $result['rt'] : []);
        DeliveryCodec::failOnNacks(is_array($result['cf'] ?? null) ? $result['cf'] : []);
    }

    /**
     * Prefer `Delivery::ack()`, which knows its own tag and refuses to settle twice.
     */
    public function ack(int $deliveryTag, bool $multiple = false): void
    {
        $this->run(
            command: AmqpCommandEnum::Ack,
            data: [
                'dt' => $deliveryTag,
                'mu' => $multiple,
            ],
            exceptionClass: QueueException::class,
            operation: 'Could not ack the delivery.',
        );
    }

    /**
     * Prefer `Delivery::nack()`.
     */
    public function nack(int $deliveryTag, bool $requeue = true, bool $multiple = false): void
    {
        $this->run(
            command: AmqpCommandEnum::Nack,
            data: [
                'dt' => $deliveryTag,
                'mu' => $multiple,
                'rq' => $requeue,
            ],
            exceptionClass: QueueException::class,
            operation: 'Could not nack the delivery.',
        );
    }

    /**
     * Refuses exactly one delivery, never a run of them. Prefer `Delivery::reject()`.
     */
    public function reject(int $deliveryTag, bool $requeue = false): void
    {
        $this->run(
            command: AmqpCommandEnum::Reject,
            data: [
                'dt' => $deliveryTag,
                'rq' => $requeue,
            ],
            exceptionClass: QueueException::class,
            operation: 'Could not reject the delivery.',
        );
    }

    /**
     * One message, or null when the queue is empty. basic.get never waits; a worker reading
     * a queue continuously wants `consume()`.
     */
    public function get(string $queueName, bool $autoAck = false): ?Delivery
    {
        $result = $this->run(
            command: AmqpCommandEnum::Get,
            data: [
                'na' => $queueName,
                'aa' => $autoAck,
            ],
            exceptionClass: QueueException::class,
            operation: 'Could not get a message from the queue.',
        );

        if ($result === []) {
            return null;
        }

        return DeliveryCodec::delivery(
            delivery: $result,
            channel: $this->weakSelf(),
            autoAck: $autoAck,
        );
    }

    /**
     * Yields every delivery in turn, suspending the coroutine between them.
     *
     * The generator owns the consumer: any way out of the loop cancels it and gives the
     * delivery stream back.
     *
     * Only a stopped flow ends the loop quietly. Everything else that takes the consumer
     * away raises — the broker cancelling it, the channel dying, and the read timeout
     * passing with nothing delivered, which is idleness rather than an ending and must not
     * read as one.
     *
     * @param array<string, mixed> $arguments consumer arguments, such as `x-priority`
     *
     * @return Generator<int, Delivery>
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
            command: AmqpCommandEnum::Consume,
            data: [
                'chid' => $this->internalId,
                'na'   => $queueName,
                'tg'   => $consumerTag,
                'aa'   => $autoAck,
                'ex'   => $exclusive,
                'nl'   => $noLocal,
                'nw'   => false,
                'ar'   => TableCodec::encode($arguments),
                'rt'   => $this->connection->readTimeoutMs(),
                'to'   => $this->connection->rpcTimeoutMs(),
            ],
            exceptionClass: QueueException::class,
            channel: $this,
            operation: 'Could not start the consumer.',
        );

        $taskKey = $result->key;

        $consumerMeta = static::decode($result);

        $registeredTag = isset($consumerMeta['tg']) ? (string) $consumerMeta['tg'] : '';

        $channel = $this->weakSelf();

        try {
            while (true) {
                $next = $this->nextStream(
                    taskKey: $taskKey,
                    exceptionClass: QueueException::class,
                    channel: $this,
                );

                if (!$next->hasNext) {
                    // The consumer is already gone; nothing left for the teardown to cancel.
                    $registeredTag = '';

                    return;
                }

                yield DeliveryCodec::delivery(
                    delivery: static::decode($next),
                    channel: $channel,
                    autoAck: $autoAck,
                );
            }
        } finally {
            State::releaseSyncTaskFlow($taskKey);

            $this->cancelConsumer(consumerTag: $registeredTag);
        }
    }

    protected function ownConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * The weak reference every Delivery of this channel is handed. WeakReference::create()
     * answers the same instance for the same object, so this allocates once per channel.
     *
     * @return WeakReference<self>
     */
    protected function weakSelf(): WeakReference
    {
        return WeakReference::create($this);
    }

    /** Gives up the handle and answers what it was, so the caller can hand it back. */
    protected function releaseHandle(): string
    {
        $channelId = $this->internalId;

        $this->internalOpen = false;
        $this->internalId   = '';

        // The registry entry is weak, but its key is not: a worker opening a channel per
        // request would grow that array for the life of the connection.
        unset($this->connection->internalChannels[$channelId]);

        return $channelId;
    }

    /**
     * Gives the channel back without waiting for the broker to answer — for an unwound
     * coroutine and for the destructor, neither of which has anything to wait on.
     *
     * Leaving it to the garbage collector is not good enough: the channel stays open until
     * it runs, and every delivery that channel never acknowledged stays owed to the broker
     * with it, for up to the broker's consumer timeout.
     */
    protected function closeDetached(): void
    {
        // Keyed on the handle, not the open flag: a failed command marks the channel closed
        // here without releasing anything on the other side.
        if ($this->internalId === '') {
            return;
        }

        $channelId = $this->releaseHandle();

        try {
            Extension::get()->push(
                flowKey: '',
                payload: new AmqpPayload(
                    command: AmqpCommandEnum::ChannelClose,
                    data: [
                        'chid' => $channelId,
                        'to'   => $this->connection->rpcTimeoutMs(),
                    ],
                ),
            );
        } catch (Throwable) {
            // The extension is gone with the process, and every channel it held with it.
        }
    }

    /**
     * Ends a consumer that its loop left before the stream did.
     *
     * Awaited whenever it can be: a cancel still in flight leaves the old consumer
     * registered, and the broker may hand a message to the stream nobody reads any more
     * while the next consume waits for a delivery that was already given away. Detached
     * only where waiting is impossible — an unwound coroutine.
     */
    protected function cancelConsumer(string $consumerTag): void
    {
        if ($consumerTag === '' || !$this->internalOpen) {
            return;
        }

        $detached = !FeatureExecutor::canAwait();

        $data = [
            'chid' => $this->internalId,
            'tg'   => $consumerTag,
            'nw'   => $detached,
            'to'   => $this->connection->rpcTimeoutMs(),
        ];

        try {
            if ($detached) {
                Extension::get()->push(
                    flowKey: '',
                    payload: new AmqpPayload(
                        command: AmqpCommandEnum::Cancel,
                        data: $data,
                    ),
                );

                return;
            }

            $this->runCommand(
                command: AmqpCommandEnum::Cancel,
                data: $data,
                exceptionClass: QueueException::class,
                channel: $this,
                operation: 'Could not cancel the consumer.',
            );
        } catch (AmqpException) {
            // Already gone: nothing left to cancel, and a teardown is no place to fail.
        }
    }

    protected static function assertPrefetch(int $count, int $sizeBytes): void
    {
        if ($count < 0 || $count > self::MAX_PREFETCH_COUNT) {
            throw new InvalidPrefetchException(
                message: "Parameter 'prefetchCount' must be between 0 and " . self::MAX_PREFETCH_COUNT . '.',
            );
        }

        if ($sizeBytes < 0 || $sizeBytes > self::MAX_PREFETCH_SIZE_BYTES) {
            throw new InvalidPrefetchException(
                message: "Parameter 'prefetchSizeBytes' must be between 0 and "
                    . self::MAX_PREFETCH_SIZE_BYTES . '.',
            );
        }
    }

    public function __destruct()
    {
        $this->closeDetached();
    }
}
