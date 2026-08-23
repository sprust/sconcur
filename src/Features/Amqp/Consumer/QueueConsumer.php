<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

use Closure;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Delivery;
use SConcur\Deadline;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;
use Throwable;

/**
 * A long-lived worker pulling several queues at once: one coroutine per unit of a queue's
 * weight, each with its own channel and consumer. While no queue has a message the PHP
 * thread is free, which is why this exists instead of a loop around Queue::get().
 *
 * Built to be supervised: the constructor takes scalars so fromArgs() can fill them from
 * the argv a WorkerMaster hands its workers, and the run ends on SIGTERM, on a life limit
 * or on losing the master — always through a drain rather than a cut (see superviseDrain).
 *
 * It declares nothing. Topology belongs to whoever owns it, and a consumer that redeclared
 * a queue with the wrong flags would take the channel down with a 406.
 */
class QueueConsumer
{
    use ServerRuntimeSupportTrait;

    protected const int DEFAULT_POLL_INTERVAL_MS = 200;

    protected const int DEFAULT_DRAIN_TIMEOUT_MS = 5000;

    /** How often the drain re-checks whether the handlers are done. */
    protected const int DRAIN_POLL_INTERVAL_MS = 20;

    /** The same default the servers use. */
    protected const int DEFAULT_PREEMPTION_QUANTUM_MS = 5;

    /** @var list<QueueSpec>|null parsed once, by queueSpecs() */
    protected ?array $specs = null;

    /**
     * Every limit takes 0 to mean "no limit"; the full table is in docs/amqp.md.
     *
     * @param string $queues              the queue list as JSON, see QueueSpecParser
     * @param int    $prefetchCount       unacknowledged messages one coroutine may hold,
     *                                    unless its queue names its own. 1 hands the next
     *                                    message to a free coroutine instead of filling the
     *                                    buffer of a busy one
     * @param int    $handlerTimeoutMs    how long one message may spend in the handler. Past
     *                                    it the handler is unwound and its message refused
     *                                    like any other failure; the coroutine survives and
     *                                    takes the next message
     * @param bool   $requeueOnFailure    where a message whose handler threw goes: false
     *                                    dead-letters it, or drops it where the queue names
     *                                    no exchange; true puts it back, which loops forever
     *                                    on a message that always fails
     * @param int    $maxMessages         drain and stop after this many. A budget, not a hard
     *                                    count: the coroutines already inside a handler
     *                                    finish theirs, so a pool of N may end up to N-1 over
     * @param int    $maxRuntimeSeconds   drain and stop after this long
     * @param int    $maxMemoryBytes      drain and stop once the PHP heap passes this
     * @param int    $drainTimeoutMs      how long a stop waits for in-flight handlers. Keep
     *                                    it below the master's shutdownTimeoutMs, or SIGKILL
     *                                    lands in the middle of the drain
     * @param int    $pollIntervalMs      how often the supervisor coroutine wakes up
     * @param int    $preemptionQuantumMs what lets $handlerTimeoutMs and a stop reach a
     *                                    handler busy with computation — and keeps such a
     *                                    handler from holding off the supervisor
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
        protected int $drainTimeoutMs = self::DEFAULT_DRAIN_TIMEOUT_MS,
        protected int $pollIntervalMs = self::DEFAULT_POLL_INTERVAL_MS,
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
     * costs one message rather than one consumer.
     *
     * @param Closure(Delivery): void             $handler
     * @param ?Closure(Throwable, Delivery): void $onError called when a handler throws;
     *                                                     without one the failure is logged
     *
     * @throws AmqpException if every consumer died on its own
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
            static::withPreemption(
                quantumMs: $this->preemptionQuantumMs,
                callback: static function () use ($waitGroup): void {
                    $waitGroup->waitAll();
                },
            );
        } finally {
            $restoreSignals();
        }

        $failure = $state->consumerFailure();

        // Every consumer is gone and nobody asked for a stop. Reporting that as a finished
        // shift would exit 0, and a pool on `restartPolicy: on-failure` would stay empty for
        // good after a broker outage. A drain that was asked for keeps its clean exit.
        if ($failure !== null && !$state->isDraining()) {
            throw $failure;
        }

        return $state->handledCount();
    }

    /**
     * One coroutine on one queue, with a channel of its own: the commands of a channel are
     * serialized, so sharing one would turn N consumers into a queue of N.
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

        $failure = null;

        try {
            foreach ($channel->consume(queueName: $spec->name) as $delivery) {
                $keepGoing = $this->handleDelivery(
                    delivery: $delivery,
                    handler: $handler,
                    onError: $onError,
                    state: $state,
                );

                if (!$keepGoing) {
                    // Dropping the generator is what cancels the consumer.
                    break;
                }
            }
        } catch (AmqpException $exception) {
            // One consumer ending is not the worker ending: the others keep their queues,
            // and the run ends on its own once the last one is gone. Letting this escape
            // would fail the group, and the stop that follows would cut every other
            // handler mid-message.
            static::logServerEvent(sprintf(
                'consumer: %s ended: %s: %s',
                $spec->name,
                $exception::class,
                $exception->getMessage(),
            ));

            $failure = $exception;
        } finally {
            $state->consumerFinished($failure);

            // Every path, the unwound one included: there the channel would otherwise go
            // back only once the garbage collector reached the cycle the unwind left, and
            // until it closes the delivery in hand stays owed to the broker. close() picks
            // an awaited or a detached release on its own.
            $channel->close();
        }
    }

    /**
     * Runs the handler for one delivery, settles it, and answers whether this consumer keeps
     * going. The handler never learns about the drain, so the same one works supervised and
     * standalone.
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
            $this->runHandler(
                handler: $handler,
                delivery: $delivery,
            );
        } catch (Throwable $exception) {
            $unwound = static::endedByStop($exception);

            if ($unwound) {
                throw $exception;
            }

            $failed = true;

            $this->reportFailure(
                exception: $exception,
                delivery: $delivery,
                onError: $onError,
            );
        } finally {
            if ($unwound) {
                $state->messageAbandoned();
            } else {
                // Settled before the consumer leaves the busy set the drain watches: one
                // that reported itself free with its acknowledgement still in flight could
                // be stopped between the two, and a finished message would go back to the
                // queue for another worker to do again.
                $this->settle(
                    delivery: $delivery,
                    failed: $failed,
                );

                $state->messageFinished();
            }
        }

        if ($state->isDraining()) {
            return false;
        }

        if ($this->maxMessages > 0 && $state->handledCount() >= $this->maxMessages) {
            // Announced rather than acted on alone: the idle consumers never reach this
            // check on their own.
            $state->startDraining();

            return false;
        }

        return true;
    }

    /**
     * Whether the runtime ended this message rather than the handler.
     *
     * A stop unwinds the coroutine without the application deciding anything, so its
     * message goes back to the broker unsettled — leaving it that way is what returns it,
     * exactly once, when the channel closes. Refusing it on the handler's behalf would
     * report a failure that never happened, and dead-letter or drop the message with it.
     *
     * The handler's own deadline is the opposite case: there the job failed, and the
     * runtime answers for the message like it does for any other failure. The two stay
     * distinguishable because CoroutineTimeoutException extends FlowStoppedException, which
     * is what that hierarchy is for.
     */
    protected static function endedByStop(Throwable $exception): bool
    {
        return $exception instanceof FlowStoppedException
            && !$exception instanceof CoroutineTimeoutException;
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
     * Answers the broker for a delivery the handler left open; one it settled itself is left
     * alone, which is what lets the runtime take the acknowledgement over at all.
     *
     * A failed settle is logged rather than thrown: the message is the broker's problem
     * again either way, and letting it escape would end a consumer over a dead channel.
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
     * The coroutine that ends the run, for two reasons.
     *
     * One: with every consumer parked on a delivery the process sits inside the extension's
     * blocking wait, no PHP runs, and a pending SIGTERM is never delivered. Sleeping through
     * the extension returns to PHP on every tick and lets the signal land.
     *
     * Two: the stop takes two phases. The drain flag stops the consumers that are working —
     * each returns once its message is settled — but says nothing to the ones waiting for a
     * delivery, which have no callback to return from. Those end by stopping the group,
     * which is safe only once nothing is mid-message; that is what the second wait is for.
     * Cancelling them instead would not do: a basic.cancel from another coroutine closes the
     * delivery stream, and the consumer parked on it reads that as a failure.
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

            if ($state->finishedConsumers() >= $consumerCount) {
                // Nothing left to supervise; consume() decides whether that is a clean end
                // or a failure to report.
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

        // Ends the consumers still waiting for a delivery, and this coroutine with them.
        // Anything mid-message either finished above or ran past the deadline, in which case
        // its message goes back to the broker unacknowledged.
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
