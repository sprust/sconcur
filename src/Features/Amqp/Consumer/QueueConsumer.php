<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Deadline;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Amqp\Payloads\AmqpPayload;
use SConcur\Features\Amqp\Support\AmqpFailure;
use SConcur\Features\Amqp\Support\DeliveryCodec;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Scheduler\Scheduler;
use SConcur\Transport\MessagePackTransport;
use Throwable;
use WeakReference;

/**
 * A long-lived worker pulling several queues at once. It is a server in everything but the
 * socket: the Go side opens the consumers and publishes every delivery of all of them as
 * one stream, `Scheduler::serve()` drives that stream, and each message is handled in a
 * coroutine of its own — the same loop, the same graceful shutdown and the same automatic
 * preemption the HTTP, socket and WebSocket servers run on.
 *
 * The channels behind the consumers belong to the Go side. That is what keeps this class
 * free of questions about the runtime: a stop cancels the consumers and leaves the channels
 * open so the acknowledgements in flight still land, and the flow ending closes them.
 *
 * Built to be supervised: the constructor takes scalars so fromArgs() can fill them from the
 * argv a WorkerMaster hands its workers, and the run ends on SIGTERM, on a life limit or on
 * losing the master.
 *
 * It declares nothing. Topology belongs to whoever owns it, and a consumer that redeclared a
 * queue with the wrong flags would take the channel down with a 406.
 */
class QueueConsumer
{
    use ServerRuntimeSupportTrait;

    /** The same default the servers use. */
    protected const int DEFAULT_PREEMPTION_QUANTUM_MS = 5;

    /** How long a consumer the broker took away waits before its queue is opened again. */
    protected const int REOPEN_INTERVAL_MS = 1_000;

    /** @var list<QueueSpec>|null parsed once, by queueSpecs() */
    protected ?array $specs = null;

    /**
     * The handles over the channels the delivery stream opened, by their Go-side id. A
     * message is settled — and, when a handler chooses to, republished — on the channel it
     * arrived on, and this is how a handler reaches it.
     *
     * Held for the run rather than per message: one channel serves many deliveries.
     *
     * @var array<string, Channel>
     */
    protected array $channels = [];

    /**
     * Every limit takes 0 to mean "no limit"; the full table is in docs/amqp.md.
     *
     * @param string $queues              the queue list as JSON, see QueueSpecParser
     * @param int    $prefetchCount       unacknowledged messages one consumer may hold,
     *                                    unless its queue names its own. It is also what
     *                                    bounds how many handlers run at once: a message
     *                                    stays unacknowledged for as long as its handler
     *                                    runs
     * @param int    $handlerTimeoutMs    how long one message may spend in the handler. Past
     *                                    it the handler is unwound and its message refused
     *                                    like any other failure; the worker carries on
     * @param bool   $requeueOnFailure    where a message whose handler threw goes: false
     *                                    dead-letters it, or drops it where the queue names
     *                                    no exchange; true puts it back, which loops forever
     *                                    on a message that always fails
     * @param int    $maxMessages         drain and stop after this many
     * @param int    $maxRuntimeSeconds   drain and stop after this long
     * @param int    $maxMemoryBytes      drain and stop once the PHP heap passes this
     * @param int    $preemptionQuantumMs what lets $handlerTimeoutMs and a stop reach a
     *                                    handler busy with computation — and keeps such a
     *                                    handler from holding off the serve loop
     * @param ?int   $masterPid           when set, the worker drains as soon as it is
     *                                    orphaned; the master injects it as --masterPid
     */
    public function __construct(
        protected string $queues = '',
        protected int $prefetchCount = 1,
        protected int $handlerTimeoutMs = 0,
        protected bool $requeueOnFailure = false,
        protected int $maxMessages = 0,
        protected int $maxRuntimeSeconds = 0,
        protected int $maxMemoryBytes = 0,
        protected int $preemptionQuantumMs = self::DEFAULT_PREEMPTION_QUANTUM_MS,
        protected ?int $masterPid = null,
    ) {
    }

    /**
     * The queues this consumer will pull, parsed and validated — which is what the worker
     * script declares before handing over, since the runtime declares nothing.
     *
     * @return list<QueueSpec>
     */
    public function queueSpecs(): array
    {
        return $this->specs ??= QueueSpecParser::parse($this->queues);
    }

    /**
     * Builds a consumer from the worker's argv (`--queues=…`, `--prefetchCount=…`, and
     * the `--masterPid` the master appends).
     *
     * @param array<int, string> $argv
     */
    public static function fromArgs(array $argv): self
    {
        return new self(...self::parseArgs($argv));
    }

    /**
     * Consumes until a stop is asked for, and answers how many messages were handled.
     *
     * The runtime settles the message, not the handler: returning acknowledges it, throwing
     * refuses it according to $requeueOnFailure. A handler that settled the delivery itself
     * is left alone, because a Delivery refuses to be settled twice — so a failed handler
     * costs one message rather than the worker.
     *
     * A stop finishes the message in hand: the consumers are cancelled first, and the loop
     * leaves only once the last handler has returned. There is no drain deadline of its own
     * — a handler that never ends is what the master's shutdownTimeoutMs is for, exactly as
     * with the servers.
     *
     * @param Closure(Delivery): void                 $handler
     * @param null|Closure(Throwable, Delivery): void $onError called when a handler throws;
     *                                                         without one it is logged
     */
    public function consume(Connection $connection, Closure $handler, null|Closure $onError = null): int
    {
        $specs = $this->queueSpecs();

        if (!$connection->isOpen()) {
            $connection->connect();
        }

        $stopRequested = false;

        // Installed before the stream opens, so a signal arriving during startup is not the
        // one that gets lost.
        $restoreSignals = $this->installSignalHandlers($stopRequested);

        $flowKey   = uniqid('amqp_', more_entropy: true);
        $startedAt = microtime(true);
        $handled   = 0;

        try {
            $runningTask = Extension::get()->push(
                flowKey: $flowKey,
                payload: new AmqpPayload(
                    command: AmqpCommandEnum::ConsumeServe,
                    data: [
                        'cid' => $connection->connectionId(),
                        'qs'  => static::queuesPayload($specs),
                        'ct'  => $this->prefetchCount,
                        'aa'  => false,
                        'rd'  => self::REOPEN_INTERVAL_MS,
                        'to'  => $connection->rpcTimeoutMs(),
                    ],
                ),
            );

            static::logServerEvent(sprintf(
                'sconcur amqp consumer started pid=%d version=%s queues=%d consumers=%d'
                . ' prefetchCount=%d maxMessages=%d',
                getmypid(),
                Extension::REQUIRED_EXTENSION_VERSION,
                count($specs),
                QueueSpecParser::channelCount($specs),
                $this->prefetchCount,
                $this->maxMessages,
            ));

            $masterPid = $this->masterPid;

            $this->serve(
                serverFlowKey: $flowKey,
                serverTaskKey: $runningTask->key,
                maxRequests: $this->maxMessages,
                onRequest: function (string $payload) use ($connection, $handler, $onError, &$handled): void {
                    $this->handleDelivery(
                        connection: $connection,
                        payload: $payload,
                        handler: $handler,
                        onError: $onError,
                        handled: $handled,
                    );
                },
                shouldStop: function () use (&$stopRequested, $masterPid, $startedAt): bool {
                    return $stopRequested
                        || ($masterPid !== null && static::isOrphaned($masterPid))
                        || $this->limitReached($startedAt);
                },
                onDrainStart: static function () use ($flowKey): void {
                    // Cancel the consumers and keep their channels: the handlers still
                    // running answer the broker on them, and a message finished with its
                    // acknowledgement cut would go back to the queue for another worker.
                    Extension::get()->amqpStopConsuming($flowKey);
                },
                onShutdownStep: static function (string $step): void {
                    static::logServerEvent('sconcur amqp consumer shutdown: ' . $step);
                },
                preemptionQuantumMs: $this->preemptionQuantumMs,
            );
        } finally {
            $restoreSignals();

            $this->channels = [];
        }

        return $handled;
    }

    /**
     * The serve loop, with the stream's failure raised as what it is.
     *
     * A stream that ends with an error carries the scope the Go side put on it, and it
     * reaches here as the generic task failure every feature gets. Left that way, a worker
     * whose broker went down would report a task error instead of a ConnectionException,
     * and nothing catching AmqpException would see it.
     *
     * The deadline of a handler is deliberately not serve()'s own: that one unwinds the
     * whole coroutine, and a message whose coroutine the runtime has let go of can no
     * longer be refused. It is put around the handler instead, in runHandler().
     *
     * @param Closure(string): void $onRequest
     * @param Closure(): bool       $shouldStop
     * @param Closure(): void       $onDrainStart
     * @param Closure(string): void $onShutdownStep
     */
    protected function serve(
        string $serverFlowKey,
        string $serverTaskKey,
        int $maxRequests,
        Closure $onRequest,
        Closure $shouldStop,
        Closure $onDrainStart,
        Closure $onShutdownStep,
        int $preemptionQuantumMs,
    ): void {
        try {
            Scheduler::get()->serve(
                serverFlowKey: $serverFlowKey,
                serverTaskKey: $serverTaskKey,
                maxRequests: $maxRequests,
                onRequest: $onRequest,
                shouldStop: $shouldStop,
                onDrainStart: $onDrainStart,
                onShutdownStep: $onShutdownStep,
                preemptionQuantumMs: $preemptionQuantumMs,
                handlerTimeoutMs: 0,
            );
        } catch (TaskErrorException $exception) {
            throw AmqpFailure::translate(
                exception: $exception,
                exceptionClass: QueueException::class,
            );
        }
    }

    /**
     * Runs the handler for one delivery and settles it, in a coroutine of its own.
     *
     * The handler never learns about the drain, so the same one works supervised and
     * standalone.
     *
     * @param Closure(Delivery): void                 $handler
     * @param null|Closure(Throwable, Delivery): void $onError
     */
    protected function handleDelivery(
        Connection $connection,
        string $payload,
        Closure $handler,
        null|Closure $onError,
        int &$handled,
    ): void {
        /** @var array<mixed> $event */
        $event = MessagePackTransport::unpack($payload);

        $delivery = DeliveryCodec::delivery(
            delivery: $event,
            channel: WeakReference::create(
                $this->channelFor(
                    connection: $connection,
                    channelId: isset($event['chid']) ? (string) $event['chid'] : '',
                ),
            ),
            autoAck: false,
        );

        $failed = false;

        try {
            $this->runHandler(
                handler: $handler,
                delivery: $delivery,
            );
        } catch (CoroutineTimeoutException $exception) {
            // Listed before FlowStoppedException, which it extends: the job ran past its
            // deadline, so it failed and its message is refused like any other failure.
            $failed = true;

            $this->reportFailure(
                exception: $exception,
                delivery: $delivery,
                onError: $onError,
            );
        } catch (FlowStoppedException) {
            // The runtime ended this message rather than the handler — shutdown reached a
            // coroutine mid-job. Nothing was decided about the message, so it is left
            // unsettled: that is what returns it to the broker, exactly once, when the
            // channel closes. Refusing it here would report a failure that never happened.
            return;
        } catch (Throwable $exception) {
            $failed = true;

            $this->reportFailure(
                exception: $exception,
                delivery: $delivery,
                onError: $onError,
            );
        }

        $this->settle(
            delivery: $delivery,
            failed: $failed,
        );

        ++$handled;
    }

    /**
     * The handle over the channel a delivery arrived on. The channel itself belongs to the
     * delivery stream; this is the object a handler settles and republishes through.
     */
    protected function channelFor(Connection $connection, string $channelId): Channel
    {
        return $this->channels[$channelId] ??= new Channel(
            connection: $connection,
            channelId: $channelId,
        );
    }

    /**
     * Runs the handler, under a deadline when the worker was given one.
     *
     * The deadline is the coroutine's, so it reaches the handler wherever it waits — and,
     * with preemption armed, code that never waits. What it cannot reach is a call already
     * inside the extension (docs/coroutine-timeout.md).
     *
     * @param Closure(Delivery): void $handler
     */
    protected function runHandler(Closure $handler, Delivery $delivery): void
    {
        if ($this->handlerTimeoutMs <= 0) {
            $handler($delivery);

            return;
        }

        Deadline::run(
            timeoutMs: $this->handlerTimeoutMs,
            callback: static function () use ($handler, $delivery): void {
                $handler($delivery);
            },
        );
    }

    /**
     * @param null|Closure(Throwable, Delivery): void $onError
     */
    protected function reportFailure(Throwable $exception, Delivery $delivery, null|Closure $onError): void
    {
        if ($onError !== null) {
            $onError($exception, $delivery);

            return;
        }

        static::logServerEvent(sprintf(
            'consumer: handler failed on %s: %s: %s',
            $delivery->routingKey,
            $exception::class,
            $exception->getMessage(),
        ));
    }

    /**
     * Answers the broker for a delivery the handler left open; one it settled itself is left
     * alone, which is what lets the runtime take the acknowledgement over at all.
     *
     * A failed settle is logged rather than thrown: the message is the broker's problem
     * again either way, and letting it escape would end the coroutine over a dead channel —
     * which the stream reopens on its own.
     */
    protected function settle(Delivery $delivery, bool $failed): void
    {
        if ($delivery->isSettled()) {
            return;
        }

        try {
            if ($failed) {
                $delivery->nack(requeue: $this->requeueOnFailure);

                return;
            }

            $delivery->ack();
        } catch (FlowStoppedException $exception) {
            // A deliberate unwind mid-settle; it must not be swallowed.
            throw $exception;
        } catch (Throwable $exception) {
            static::logServerEvent(sprintf(
                'consumer: could not settle delivery %d: %s: %s',
                $delivery->deliveryTag,
                $exception::class,
                $exception->getMessage(),
            ));
        }
    }

    /** Whether a life limit says this worker has done its shift. */
    protected function limitReached(float $startedAt): bool
    {
        if ($this->maxRuntimeSeconds > 0 && (microtime(true) - $startedAt) >= $this->maxRuntimeSeconds) {
            return true;
        }

        return $this->maxMemoryBytes > 0 && memory_get_usage() >= $this->maxMemoryBytes;
    }

    /**
     * The queue list as the Go side takes it: a queue's weight is how many consumers it
     * gets, each on a channel of its own.
     *
     * @param list<QueueSpec> $specs
     *
     * @return list<array<string, mixed>>
     */
    protected static function queuesPayload(array $specs): array
    {
        $queues = [];

        foreach ($specs as $spec) {
            $queues[] = [
                'na' => $spec->name,
                'cn' => $spec->coroutineCount,
                'ct' => $spec->prefetchCount ?? 0,
            ];
        }

        return $queues;
    }
}
