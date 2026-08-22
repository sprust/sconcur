<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Connection\Extension;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\Amqp\Payloads\ChannelClosePayload;
use SConcur\Features\Amqp\Payloads\ChannelOpenPayload;
use SConcur\Features\Amqp\Payloads\ChannelOpenPayloadParameters;
use SConcur\Features\Amqp\Payloads\ChannelPayloadParameters;
use SConcur\Features\Amqp\Payloads\ConfirmSelectPayload;
use SConcur\Features\Amqp\Payloads\ConfirmSelectPayloadParameters;
use SConcur\Features\Amqp\Payloads\ConfirmWaitPayload;
use SConcur\Features\Amqp\Payloads\QosPayload;
use SConcur\Features\Amqp\Payloads\QosPayloadParameters;
use SConcur\Features\Amqp\Payloads\RecoverPayload;
use SConcur\Features\Amqp\Payloads\RecoverPayloadParameters;
use SConcur\Features\Amqp\Payloads\ReturnWaitPayload;
use SConcur\Features\Amqp\Payloads\TransactionCommitPayload;
use SConcur\Features\Amqp\Payloads\TransactionRollbackPayload;
use SConcur\Features\Amqp\Payloads\TransactionSelectPayload;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Amqp\Support\PropertiesCodec;
use SConcur\Transport\PayloadInterface;
use Throwable;
use WeakReference;

/**
 * A channel of an AMQP connection — the calque of ext-amqp's AMQPChannel. The constructor
 * opens it on the broker, as in the extension, and applies the prefetch settings; from
 * there on it is the handle every exchange, queue and delivery acknowledgement of this
 * channel travels through.
 *
 * A channel is not shared between coroutines: delivery tags belong to the channel that
 * delivered them, so a consumer and the acknowledgements of its messages must live on the
 * same one.
 */
class AMQPChannel extends AmqpResource
{
    /** The prefetch count ext-amqp gives a fresh channel. */
    protected const int DEFAULT_PREFETCH_COUNT = 3;

    protected const int MAX_PREFETCH_COUNT = 65535;

    protected const int MAX_PREFETCH_SIZE_BYTES = 4294967295;

    protected AMQPConnection $connection;

    /** The channel number the broker assigned. */
    protected int $channelNumber = 0;

    protected int $prefetchCount = self::DEFAULT_PREFETCH_COUNT;

    protected int $prefetchSize = 0;

    protected int $globalPrefetchCount = 0;

    protected int $globalPrefetchSize = 0;

    /** @var callable|null */
    protected $confirmCallback;

    /** @var callable|null */
    protected $nackCallback;

    /** @var callable|null */
    protected $returnCallback;

    /**
     * Opens a channel on an already connected AMQPConnection.
     *
     * @throws AMQPConnectionException if the connection is not open or the broker is gone
     */
    public function __construct(AMQPConnection $connection)
    {
        $this->connection = $connection;

        if (!$connection->isConnected()) {
            throw new AMQPConnectionException(
                message: 'Could not create channel. No connection available.',
            );
        }

        $result = $this->runCommand(
            payload: new ChannelOpenPayload(
                new ChannelOpenPayloadParameters(
                    connectionId: $connection->internalId,
                    prefetchSizeBytes: $this->prefetchSize,
                    prefetchCount: $this->prefetchCount,
                    globalPrefetchSizeBytes: $this->globalPrefetchSize,
                    globalPrefetchCount: $this->globalPrefetchCount,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPConnectionException::class,
        );

        $this->internalId    = isset($result['chid']) ? (string) $result['chid'] : '';
        $this->channelNumber = isset($result['no']) ? (int) $result['no'] : 0;
        $this->internalOpen  = true;

        // The connection keeps a weak reference so disconnect() can mark this closed:
        // it is the release of the handle that actually closes the channel on the Go
        // side, and only the connection knows when that happens.
        $connection->internalChannels[$this->internalId] = WeakReference::create($this);
    }

    /**
     * Whether the channel is open. Like the extension, it reports what happened on this
     * side and does not probe the broker.
     */
    public function isConnected(): bool
    {
        return $this->internalOpen && $this->connection->isConnected();
    }

    /**
     * Closes the channel. Idempotent and best-effort: a channel the broker already
     * dropped needs no closing.
     */
    public function close(): void
    {
        if (!$this->internalOpen) {
            return;
        }

        $channelId = $this->internalId;

        // The consumers of this channel go with it, and so do the streams they were being
        // read through: outside a coroutine each of those owns a flow that nothing else
        // would release.
        foreach ($this->getConsumers() as $queue) {
            $queue->releaseConsumeStream();
        }

        $this->internalOpen      = false;
        $this->internalId        = '';
        $this->internalConsumers = [];

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
                exceptionClass: AMQPChannelException::class,
            );
        } catch (FlowStoppedException $exception) {
            // A deliberate unwind is not a failed close: re-thrown as-is so the
            // cancellation stays recognizable, as the project's rule requires.
            throw $exception;
        } catch (Throwable) {
            // The channel is already gone — nothing to close.
        }
    }

    /** The channel number on the connection. */
    public function getChannelId(): int
    {
        return $this->channelNumber;
    }

    /**
     * How much the broker may push to this channel before it is acknowledged: a window in
     * octets, a message count, or both. With $global the limits apply to the whole
     * channel instead of to each consumer separately.
     *
     * @throws AMQPChannelException if the broker rejects the settings
     */
    public function qos(int $size, int $count, bool $global = false): void
    {
        if ($global) {
            $this->globalPrefetchSize  = $size;
            $this->globalPrefetchCount = $count;
        } else {
            $this->prefetchSize  = $size;
            $this->prefetchCount = $count;
        }

        $this->applyQos();
    }

    /**
     * @throws AMQPConnectionException if the count is outside the range the protocol allows
     * @throws AMQPChannelException if the broker rejects the settings
     */
    public function setPrefetchCount(int $count): void
    {
        $this->assertPrefetchCount($count);

        $this->prefetchCount = $count;
        $this->prefetchSize  = 0;

        $this->applyQos();
    }

    public function getPrefetchCount(): int
    {
        return $this->prefetchCount;
    }

    /**
     * @throws AMQPConnectionException if the window is outside the range the protocol allows
     * @throws AMQPChannelException if the broker rejects the settings
     */
    public function setPrefetchSize(int $size): void
    {
        $this->assertPrefetchSize($size);

        $this->prefetchSize  = $size;
        $this->prefetchCount = 0;

        $this->applyQos();
    }

    public function getPrefetchSize(): int
    {
        return $this->prefetchSize;
    }

    /**
     * @throws AMQPConnectionException if the count is outside the range the protocol allows
     * @throws AMQPChannelException if the broker rejects the settings
     */
    public function setGlobalPrefetchCount(int $count): void
    {
        $this->assertPrefetchCount($count);

        $this->globalPrefetchCount = $count;
        $this->globalPrefetchSize  = 0;

        $this->applyQos();
    }

    public function getGlobalPrefetchCount(): int
    {
        return $this->globalPrefetchCount;
    }

    /**
     * @throws AMQPConnectionException if the window is outside the range the protocol allows
     * @throws AMQPChannelException if the broker rejects the settings
     */
    public function setGlobalPrefetchSize(int $size): void
    {
        $this->assertPrefetchSize($size);

        $this->globalPrefetchSize  = $size;
        $this->globalPrefetchCount = 0;

        $this->applyQos();
    }

    public function getGlobalPrefetchSize(): int
    {
        return $this->globalPrefetchSize;
    }

    /**
     * Starts a transaction: everything published and acknowledged from here on takes
     * effect only when commitTransaction() is called.
     *
     * @throws AMQPChannelException if the broker rejects the method
     */
    public function startTransaction(): void
    {
        $this->runChannelCommand(
            payload: new TransactionSelectPayload($this->channelParameters()),
            operation: 'Could not start the transaction.',
        );
    }

    /**
     * @throws AMQPChannelException if no transaction was started or the broker rejects it
     */
    public function commitTransaction(): void
    {
        $this->runChannelCommand(
            payload: new TransactionCommitPayload($this->channelParameters()),
            operation: 'Could not commit the transaction.',
        );
    }

    /**
     * @throws AMQPChannelException if no transaction was started or the broker rejects it
     */
    public function rollbackTransaction(): void
    {
        $this->runChannelCommand(
            payload: new TransactionRollbackPayload($this->channelParameters()),
            operation: 'Could not rollback the transaction.',
        );
    }

    public function getConnection(): AMQPConnection
    {
        return $this->connection;
    }

    /**
     * Asks the broker to redeliver every message this channel has not acknowledged. With
     * $requeue the messages may go to another consumer.
     *
     * RabbitMQ only implements $requeue = true and answers false with a connection-level
     * error, so this is one of the methods that can take the whole connection down.
     *
     * @throws AMQPConnectionException if the broker refuses it at connection level
     * @throws AMQPChannelException if the channel is closed or the broker rejects the method
     */
    public function basicRecover(bool $requeue = true): void
    {
        $this->runCommand(
            payload: new RecoverPayload(
                new RecoverPayloadParameters(
                    channelId: $this->internalId,
                    requeue: $requeue,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPChannelException::class,
            channel: $this,
            operation: 'Could not redeliver unacknowledged messages.',
        );
    }

    /**
     * Puts the channel into publisher-confirm mode: the broker reports every published
     * message as confirmed or rejected, and waitForConfirm() collects those reports.
     *
     * @throws AMQPChannelException if the channel is transactional or the broker rejects it
     */
    public function confirmSelect(): void
    {
        $this->runCommand(
            payload: new ConfirmSelectPayload(
                new ConfirmSelectPayloadParameters(
                    channelId: $this->internalId,
                    noWait: false,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPChannelException::class,
            channel: $this,
            operation: 'Could not enter confirm mode.',
        );
    }

    /**
     * The callbacks waitForConfirm() runs for each confirmed or rejected message:
     *
     *     function ackCallback(int $deliveryTag, bool $multiple): bool;
     *     function nackCallback(int $deliveryTag, bool $multiple, bool $requeue): bool;
     *
     * Returning false from either ends the wait loop. Unlike the extension, which calls
     * them from its own reading loop, the calque calls them from waitForConfirm() — the
     * only place where the coroutine waits for the broker.
     */
    public function setConfirmCallback(?callable $ackCallback, ?callable $nackCallback = null): void
    {
        $this->confirmCallback = $ackCallback;
        $this->nackCallback    = $nackCallback;
    }

    /**
     * Waits until every message published since the last call has been confirmed or
     * rejected by the broker, running the confirm callbacks on the way. The messages the
     * broker returned as unroutable are collected too, and handed to the return callback.
     *
     * @param float $timeout seconds to wait; 0 waits until the broker answers
     *
     * @throws AMQPQueueException if the wait times out
     */
    public function waitForConfirm(float $timeout = 0.0): void
    {
        $result = $this->runCommand(
            payload: new ConfirmWaitPayload(
                new ChannelPayloadParameters(
                    channelId: $this->internalId,
                    timeoutMs: static::toMilliseconds($timeout),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this,
            operation: 'Could not wait for the publisher confirms.',
        );

        $this->runConfirmCallbacks(is_array($result['cf'] ?? null) ? $result['cf'] : []);
        $this->runReturnCallbacks(is_array($result['rt'] ?? null) ? $result['rt'] : []);
    }

    /**
     * The callback waitForBasicReturn() runs for each returned message:
     *
     *     function callback(int $replyCode, string $replyText, string $exchange,
     *                       string $routingKey, AMQPBasicProperties $properties,
     *                       string $body): bool;
     *
     * Returning false ends the wait loop.
     */
    public function setReturnCallback(?callable $returnCallback): void
    {
        $this->returnCallback = $returnCallback;
    }

    /**
     * Waits for the messages the broker returned as unroutable and runs the return
     * callback for each.
     *
     * @param float $timeout seconds to wait; 0 waits until the broker answers
     *
     * @throws AMQPQueueException if the wait times out
     */
    public function waitForBasicReturn(float $timeout = 0.0): void
    {
        $result = $this->runCommand(
            payload: new ReturnWaitPayload(
                new ChannelPayloadParameters(
                    channelId: $this->internalId,
                    timeoutMs: static::toMilliseconds($timeout),
                ),
            ),
            exceptionClass: AMQPQueueException::class,
            channel: $this,
            operation: 'Could not wait for the returned messages.',
        );

        $this->runReturnCallbacks(is_array($result['rt'] ?? null) ? $result['rt'] : []);
    }

    /**
     * The queues consuming on this channel, by the consumer tag the broker assigned. A
     * queue the application has dropped is gone from here with it.
     *
     * @return array<string, AMQPQueue>
     */
    public function getConsumers(): array
    {
        $consumers = [];

        foreach ($this->internalConsumers as $tag => $reference) {
            $queue = $reference->get();

            if ($queue === null) {
                continue;
            }

            $consumers[$tag] = $queue;
        }

        return $consumers;
    }

    /**
     * The deadline of one broker method on this channel, in milliseconds — the
     * connection's rpc_timeout.
     */
    protected function timeoutMs(): int
    {
        return static::toMilliseconds($this->connection->getRpcTimeout());
    }

    protected function channelParameters(): ChannelPayloadParameters
    {
        return new ChannelPayloadParameters(
            channelId: $this->internalId,
            timeoutMs: $this->timeoutMs(),
        );
    }

    /**
     * @throws AMQPChannelException if the channel is closed or the broker rejects the method
     */
    protected function runChannelCommand(PayloadInterface $payload, string $operation): void
    {
        $this->runCommand(
            payload: $payload,
            exceptionClass: AMQPChannelException::class,
            channel: $this,
            operation: $operation,
        );
    }

    /**
     * Sends the channel's prefetch settings, mirroring the extension: the per-consumer
     * limits first, then the channel-wide ones if any is set — writing the per-consumer
     * limits clears them on the broker.
     *
     * @throws AMQPConnectionException if the channel is closed
     * @throws AMQPChannelException if the broker rejects the settings
     */
    protected function applyQos(): void
    {
        if (!$this->internalOpen) {
            // The wording is the extension's: closing a channel releases the connection
            // resource its prefetch settings would have travelled on.
            throw new AMQPConnectionException(
                message: 'Could not set prefetch count. Stale reference to the connection object.',
            );
        }

        $this->runCommand(
            payload: new QosPayload(
                new QosPayloadParameters(
                    channelId: $this->internalId,
                    prefetchSizeBytes: $this->prefetchSize,
                    prefetchCount: $this->prefetchCount,
                    global: false,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPChannelException::class,
            channel: $this,
            operation: 'Could not set qos parameters.',
        );

        if ($this->globalPrefetchSize === 0 && $this->globalPrefetchCount === 0) {
            return;
        }

        $this->runCommand(
            payload: new QosPayload(
                new QosPayloadParameters(
                    channelId: $this->internalId,
                    prefetchSizeBytes: $this->globalPrefetchSize,
                    prefetchCount: $this->globalPrefetchCount,
                    global: true,
                    timeoutMs: $this->timeoutMs(),
                ),
            ),
            exceptionClass: AMQPChannelException::class,
            channel: $this,
            operation: 'Could not set qos parameters.',
        );
    }

    /**
     * @param array<mixed> $confirmations
     */
    protected function runConfirmCallbacks(array $confirmations): void
    {
        foreach ($confirmations as $confirmation) {
            if (!is_array($confirmation)) {
                continue;
            }

            $deliveryTag = isset($confirmation['dt']) ? (int) $confirmation['dt'] : 0;
            $multiple    = (bool) ($confirmation['mu'] ?? false);
            $acked       = (bool) ($confirmation['ak'] ?? false);

            $callback = $acked ? $this->confirmCallback : $this->nackCallback;

            if ($callback === null) {
                continue;
            }

            $keepWaiting = $acked
                ? $callback($deliveryTag, $multiple)
                : $callback($deliveryTag, $multiple, (bool) ($confirmation['rq'] ?? false));

            if ($keepWaiting === false) {
                return;
            }
        }
    }

    /**
     * @param array<mixed> $returns
     */
    protected function runReturnCallbacks(array $returns): void
    {
        if ($this->returnCallback === null) {
            return;
        }

        foreach ($returns as $returned) {
            if (!is_array($returned)) {
                continue;
            }

            /** @var array<mixed> $rawProperties */
            $rawProperties = is_array($returned['ps'] ?? null) ? $returned['ps'] : [];

            $keepWaiting = ($this->returnCallback)(
                isset($returned['rc']) ? (int) $returned['rc'] : 0,
                isset($returned['rx']) ? (string) $returned['rx'] : '',
                isset($returned['en']) ? (string) $returned['en'] : '',
                isset($returned['rk']) ? (string) $returned['rk'] : '',
                PropertiesCodec::decode($rawProperties),
                isset($returned['bd']) ? (string) $returned['bd'] : '',
            );

            if ($keepWaiting === false) {
                return;
            }
        }
    }

    /**
     * @throws AMQPConnectionException if the count is outside the range the protocol allows
     */
    protected function assertPrefetchCount(int $count): void
    {
        if ($count < 0 || $count > self::MAX_PREFETCH_COUNT) {
            throw new AMQPConnectionException(
                message: "Parameter 'prefetchCount' must be between 0 and " . self::MAX_PREFETCH_COUNT . '.',
            );
        }
    }

    /**
     * @throws AMQPConnectionException if the window is outside the range the protocol allows
     */
    protected function assertPrefetchSize(int $sizeBytes): void
    {
        if ($sizeBytes < 0 || $sizeBytes > self::MAX_PREFETCH_SIZE_BYTES) {
            throw new AMQPConnectionException(
                message: "Parameter 'prefetchSize' must be between 0 and " . self::MAX_PREFETCH_SIZE_BYTES . '.',
            );
        }
    }

    /**
     * A channel an application dropped without closing is closed best-effort here.
     *
     * The command goes out detached — pushed with no flow and no result to await. A
     * destructor has nothing to wait on, and the case that matters most is the coroutine
     * that was unwound (WaitGroup::stop, an early break): its flow is already gone, so an
     * ordinary command would fail and the channel would stay open on the broker until the
     * idle sweeper noticed, half an hour later.
     */
    public function __destruct()
    {
        if (!$this->internalOpen) {
            return;
        }

        $channelId = $this->internalId;

        $this->internalOpen      = false;
        $this->internalId        = '';
        $this->internalConsumers = [];

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
}
