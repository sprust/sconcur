<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

use Closure;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;
use Throwable;

/**
 * A long-lived worker that pulls several queues at once: one coroutine per unit of a
 * queue's weight, each with its own channel and its own consumer on it, all in one
 * process. While no queue has a message the PHP thread is free, which is the whole
 * reason this exists instead of a loop around Queue::get().
 *
 * It is built to be supervised. Constructor parameters are scalars so fromArgs() can
 * fill them from the argv a WorkerMaster hands its workers; the run ends on SIGTERM,
 * on one of the life limits, or when the master goes away, and the exit is a drain
 * rather than a cut (see superviseDrain).
 *
 * What it deliberately does not do: declare anything. Topology belongs to whoever owns
 * it, and a consumer that redeclared a queue with the wrong flags would take the
 * channel down with a 406 instead of consuming.
 */
class QueueConsumer
{
    use ServerRuntimeSupportTrait;

    /** How often the supervisor coroutine wakes to look at the flags. */
    protected const int DEFAULT_POLL_INTERVAL_MS = 200;

    /** How long the drain waits for the handlers that were mid-message. */
    protected const int DEFAULT_DRAIN_TIMEOUT_MS = 5000;

    /** How often the drain re-checks whether the handlers are done. */
    protected const int DRAIN_POLL_INTERVAL_MS = 20;

    /** @var list<QueueSpec>|null parsed once, by queueSpecs() */
    protected ?array $specs = null;

    /**
     * @param string $queues            the queue list as JSON, see QueueSpecParser
     * @param int    $prefetchCount     unacknowledged messages one coroutine may hold,
     *                                  unless its queue names its own. 1 hands the next
     *                                  message to a free coroutine instead of filling
     *                                  the buffer of a busy one
     * @param bool   $requeueOnFailure  what to do with a message whose handler threw and
     *                                  did not settle it. false sends it to the queue's
     *                                  dead-letter exchange, or drops it where there is
     *                                  none; true puts it back, which loops forever on a
     *                                  message that always fails
     * @param int    $maxMessages       stop after this many messages; 0 = no limit
     * @param int    $maxRuntimeSeconds stop after this long; 0 = no limit
     * @param int    $maxMemoryBytes    stop once the PHP heap passes this; 0 = no limit
     * @param int    $drainTimeoutMs    how long a stop waits for in-flight handlers.
     *                                  Keep it below the master's shutdownTimeoutMs, or
     *                                  SIGKILL lands in the middle of the drain
     * @param int    $pollIntervalMs    how often the supervisor coroutine wakes up
     * @param ?int   $masterPid         when set, the worker drains as soon as it is
     *                                  orphaned; the master injects it as --masterPid
     */
    public function __construct(
        protected string $queues = '',
        protected int $prefetchCount = 1,
        protected bool $requeueOnFailure = false,
        protected int $maxMessages = 0,
        protected int $maxRuntimeSeconds = 0,
        protected int $maxMemoryBytes = 0,
        protected int $drainTimeoutMs = self::DEFAULT_DRAIN_TIMEOUT_MS,
        protected int $pollIntervalMs = self::DEFAULT_POLL_INTERVAL_MS,
        protected ?int $masterPid = null,
    ) {
    }

    /**
     * The queues this consumer will pull, parsed and validated. A worker script owns
     * its topology — the runtime declares nothing — so this is what it declares before
     * handing over: asking here beats parsing the same argv flag a second time.
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
     * Consumes until a stop is asked for, and returns how many messages were handled.
     *
     * The runtime settles the message, not the handler: a handler that returns
     * acknowledges it, one that throws refuses it according to $requeueOnFailure. A
     * handler that settled the delivery itself — a selective reject, an ack before some
     * slow follow-up work — is left alone, because a Delivery refuses to be settled twice.
     *
     * This is what the calque could not do. There the acknowledgement belonged to the
     * handler, so a handler that threw might or might not have settled its message, and
     * the only safe answer was to end the coroutine and let the closing channel hand the
     * message back. Now the runtime knows, so a failed handler costs one message rather
     * than one consumer.
     *
     * @param Closure(Delivery): void             $handler
     * @param ?Closure(Throwable, Delivery): void $onError called when a handler throws;
     *                                                     without one the failure is logged
     */
    public function consume(Connection $connection, Closure $handler, ?Closure $onError = null): int
    {
        $specs = $this->queueSpecs();

        $state = new ConsumerState();

        $stopRequested = false;

        // Installed before the first consumer starts, so a signal arriving during
        // startup is not the one that gets lost.
        $restoreSignals = $this->installSignalHandlers($stopRequested);

        $waitGroup = WaitGroup::create();

        foreach ($specs as $spec) {
            for ($index = 0; $index < $spec->coroutineCount; ++$index) {
                $waitGroup->add(function () use ($connection, $spec, $handler, $onError, $state): void {
                    $this->consumeQueue(
                        connection: $connection,
                        spec: $spec,
                        handler: $handler,
                        onError: $onError,
                        state: $state,
                    );
                });
            }
        }

        $consumerCount = QueueSpecParser::channelCount($specs);

        $waitGroup->add(function () use ($waitGroup, $state, $consumerCount, &$stopRequested): void {
            $this->superviseDrain(
                waitGroup: $waitGroup,
                state: $state,
                consumerCount: $consumerCount,
                stopRequested: $stopRequested,
            );
        });

        try {
            $waitGroup->waitAll();
        } finally {
            $restoreSignals();
        }

        return $state->handledCount();
    }

    /**
     * One coroutine on one queue: its own channel, its own consumer, its own prefetch.
     * A channel is never shared between coroutines — the commands of one are
     * serialized, so sharing would turn N consumers into a queue of N.
     *
     * @param Closure(Delivery): void             $handler
     * @param ?Closure(Throwable, Delivery): void $onError
     */
    protected function consumeQueue(
        Connection $connection,
        QueueSpec $spec,
        Closure $handler,
        ?Closure $onError,
        ConsumerState $state,
    ): void {
        $channel = $connection->channel(prefetchCount: $spec->prefetchCount ?? $this->prefetchCount);

        $state->consumerStarted();

        // Whether this coroutine reached the end of its own consuming, one way or another.
        // False means it was unwound — the drain gave up on it and stopped the group while
        // it was still working.
        $ended = false;

        try {
            foreach ($channel->consume(queueName: $spec->name) as $delivery) {
                $keepGoing = $this->handleDelivery(
                    delivery: $delivery,
                    handler: $handler,
                    onError: $onError,
                    state: $state,
                );

                if (!$keepGoing) {
                    // Leaving the loop drops the generator, and its own teardown cancels
                    // the consumer and gives the delivery stream back.
                    break;
                }
            }

            $ended = true;
        } catch (AmqpException $exception) {
            // One consumer ending is not the worker ending. The broker cancelled it, its
            // queue was deleted, its channel died — whatever it was, the other coroutines
            // keep their queues, and the run ends on its own once the last consumer is
            // gone. Letting this escape would fail the whole group, and the stop that
            // follows would cut every other handler mid-message.
            static::logServerEvent(sprintf(
                'consumer: %s ended: %s: %s',
                $spec->name,
                $exception::class,
                $exception->getMessage(),
            ));

            $ended = true;
        } finally {
            $state->consumerFinished();

            if (!$ended) {
                // Unwound. An awaited close would suspend a fiber the scheduler has
                // already detached, so the release goes out detached — and it goes out
                // here rather than whenever the garbage collector reaches the cycle the
                // unwind left behind. Until the channel closes, the delivery this handler
                // was working on stays owed to the broker instead of going back to the
                // queue for the next worker.
                $channel->closeDetached();
            }
        }

        $channel->close();
    }

    /**
     * Runs the handler for one delivery, settles it, and answers whether this consumer
     * keeps going. The handler never learns about the drain: deciding when to stop
     * belongs here, so the same handler works supervised and standalone.
     *
     * @param Closure(Delivery): void             $handler
     * @param ?Closure(Throwable, Delivery): void $onError
     */
    protected function handleDelivery(
        Delivery $delivery,
        Closure $handler,
        ?Closure $onError,
        ConsumerState $state,
    ): bool {
        $state->messageStarted();

        $failed  = false;
        $unwound = false;

        try {
            $handler($delivery);
        } catch (FlowStoppedException $exception) {
            // The drain deadline passed while this handler was still working, and the
            // group was stopped under it. That is not a handler failure: the application
            // never got to decide, so the message must go back to the broker rather than
            // be refused on its behalf. Leaving it unsettled is what returns it — the
            // channel closing behind this coroutine hands it back exactly once.
            $unwound = true;

            throw $exception;
        } catch (Throwable $exception) {
            $failed = true;

            $this->reportFailure(exception: $exception, delivery: $delivery, onError: $onError);
        } finally {
            $state->messageFinished($failed);

            if (!$unwound) {
                $this->settle(delivery: $delivery, failed: $failed);
            }
        }

        if ($state->isDraining()) {
            return false;
        }

        if ($this->maxMessages > 0 && $state->handledCount() >= $this->maxMessages) {
            // The other consumers are idle and will not reach this check on their own,
            // so the limit is announced rather than acted on alone.
            $state->startDraining();

            return false;
        }

        return true;
    }

    /**
     * @param ?Closure(Throwable, Delivery): void $onError
     */
    protected function reportFailure(Throwable $exception, Delivery $delivery, ?Closure $onError): void
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
     * Answers the broker for a delivery the handler left open. A handler that settled it
     * itself is left alone — Delivery::isSettled() is what makes that decidable, and it is
     * the whole reason the runtime can take the acknowledgement over.
     *
     * A settle that fails is logged rather than thrown: the message is the broker's
     * problem again either way, and letting it escape would end a consumer over a channel
     * that is already gone.
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
        } catch (AmqpException $exception) {
            static::logServerEvent(sprintf(
                'consumer: could not settle delivery %d: %s: %s',
                $delivery->deliveryTag,
                $exception::class,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * The coroutine that ends the run. It exists for two reasons.
     *
     * One: while every consumer is parked waiting for a delivery, the process sits
     * inside the extension's blocking wait and no PHP runs, so a pending SIGTERM is
     * never delivered. This coroutine sleeps through the extension instead, which
     * returns to PHP on every tick and lets the signal land.
     *
     * Two: the stop itself takes two phases. Setting the drain flag stops the
     * consumers that are working — each returns false once its message is finished and
     * acknowledged. It does nothing for the consumers that are waiting for a delivery:
     * there is no callback to return from. Those end by stopping the group, which
     * unwinds them at their suspension point — safe only once nothing is mid-message,
     * which is what the wait below is for. Cancelling them instead would not do: a
     * basic.cancel from another coroutine closes the delivery stream, and the consumer
     * parked on it surfaces that as a failure rather than a clean end.
     */
    protected function superviseDrain(
        WaitGroup $waitGroup,
        ConsumerState $state,
        int $consumerCount,
        bool &$stopRequested,
    ): void {
        $startedAt = microtime(true);

        while (true) {
            Sleeper::usleep(microseconds: $this->pollIntervalMs * 1000);

            if ($state->startedConsumers() >= $consumerCount && $state->liveConsumers() === 0) {
                // Every consumer ended on its own — a handler threw, the queue was
                // deleted, the channel died. Nothing left to supervise. Checked against
                // the expected count as well, because this coroutine's first wakeup can
                // beat the consumers to their first line.
                return;
            }

            if ($state->isDraining() || $stopRequested || $this->limitReached($startedAt)) {
                break;
            }
        }

        $state->startDraining();

        $deadline = microtime(true) + $this->drainTimeoutMs / 1000;

        while ($state->busyConsumers() > 0 && microtime(true) < $deadline) {
            Sleeper::usleep(microseconds: self::DRAIN_POLL_INTERVAL_MS * 1000);
        }

        // Ends the consumers still waiting for a delivery, and this coroutine with
        // them. Anything mid-message either finished above or ran past the deadline,
        // in which case its message goes back to the broker unacknowledged.
        $waitGroup->stop();
    }

    /** Whether a life limit says this worker has done its shift. */
    protected function limitReached(float $startedAt): bool
    {
        if ($this->masterPid !== null && static::isOrphaned($this->masterPid)) {
            return true;
        }

        if ($this->maxRuntimeSeconds > 0 && (microtime(true) - $startedAt) >= $this->maxRuntimeSeconds) {
            return true;
        }

        return $this->maxMemoryBytes > 0 && memory_get_usage() >= $this->maxMemoryBytes;
    }
}
