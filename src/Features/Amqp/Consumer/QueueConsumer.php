<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

use Closure;
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;
use Throwable;

/**
 * A long-lived worker that pulls several queues at once: one coroutine per unit of a
 * queue's weight, each with its own channel and its own consumer on it, all in one
 * process. While no queue has a message the PHP thread is free, which is the whole
 * reason this exists instead of a loop around AMQPQueue::get().
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

    /**
     * @param string $queues            the queue list as JSON, see QueueSpecParser
     * @param int    $prefetchCount     unacknowledged messages one coroutine may hold,
     *                                  unless its queue names its own. 1 hands the next
     *                                  message to a free coroutine instead of filling
     *                                  the buffer of a busy one
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
        protected int $maxMessages = 0,
        protected int $maxRuntimeSeconds = 0,
        protected int $maxMemoryBytes = 0,
        protected int $drainTimeoutMs = self::DEFAULT_DRAIN_TIMEOUT_MS,
        protected int $pollIntervalMs = self::DEFAULT_POLL_INTERVAL_MS,
        protected ?int $masterPid = null,
    ) {
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
     * The handler is called with the delivery and the queue it came from, and owns the
     * acknowledgement — the same contract AMQPQueue::consume() has, so a handler moves
     * between the two unchanged.
     *
     * A handler that throws is reported through $onError and ends the coroutine it ran
     * in: closing that channel hands the message back to the broker exactly once,
     * whereas answering for a handler that may already have acknowledged it risks a
     * double settle. The run goes on with the remaining coroutines, and ends once the
     * last one is gone.
     *
     * @param Closure(AMQPEnvelope, AMQPQueue): void  $handler
     * @param ?Closure(Throwable, AMQPEnvelope): void $onError called when a handler throws;
     *                                                         without one the failure is logged
     */
    public function consume(AMQPConnection $connection, Closure $handler, ?Closure $onError = null): int
    {
        $specs = QueueSpecParser::parse($this->queues);

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
     * @param Closure(AMQPEnvelope, AMQPQueue): void  $handler
     * @param ?Closure(Throwable, AMQPEnvelope): void $onError
     */
    protected function consumeQueue(
        AMQPConnection $connection,
        QueueSpec $spec,
        Closure $handler,
        ?Closure $onError,
        ConsumerState $state,
    ): void {
        $channel = new AMQPChannel($connection);

        $channel->setPrefetchCount($spec->prefetchCount ?? $this->prefetchCount);

        $queue = new AMQPQueue($channel);

        $queue->setName($spec->name);

        $state->consumerStarted();

        try {
            $queue->consume(
                callback: fn(AMQPEnvelope $envelope, AMQPQueue $deliveredOn): bool => $this->handleDelivery(
                    envelope: $envelope,
                    queue: $deliveredOn,
                    handler: $handler,
                    onError: $onError,
                    state: $state,
                ),
            );
        } finally {
            $state->consumerFinished();

            $channel->close();
        }
    }

    /**
     * Runs the handler for one delivery and answers whether this consumer keeps going.
     * The handler never learns about the drain: deciding when to stop belongs here, so
     * the same handler works supervised and standalone.
     *
     * @param Closure(AMQPEnvelope, AMQPQueue): void  $handler
     * @param ?Closure(Throwable, AMQPEnvelope): void $onError
     */
    protected function handleDelivery(
        AMQPEnvelope $envelope,
        AMQPQueue $queue,
        Closure $handler,
        ?Closure $onError,
        ConsumerState $state,
    ): bool {
        $state->messageStarted();

        $failed = false;

        try {
            $handler($envelope, $queue);
        } catch (Throwable $exception) {
            $failed = true;

            $this->reportFailure(exception: $exception, envelope: $envelope, onError: $onError);
        } finally {
            $state->messageFinished($failed);
        }

        if ($failed) {
            // This consumer stops here. The handler owns the acknowledgement, and one
            // that threw may or may not have settled the message — nacking on its
            // behalf could be a double settle, which the broker answers by killing the
            // channel. Ending the consumer closes its channel instead, which returns
            // the message to the queue exactly once. Carrying on would be worse than
            // losing the capacity: with a prefetch of one an unsettled message means
            // the broker never sends this consumer another, and it would sit there
            // alive and idle forever.
            return false;
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
     * @param ?Closure(Throwable, AMQPEnvelope): void $onError
     */
    protected function reportFailure(Throwable $exception, AMQPEnvelope $envelope, ?Closure $onError): void
    {
        if ($onError !== null) {
            $onError($exception, $envelope);

            return;
        }

        static::logServerEvent(sprintf(
            'consumer: handler failed on %s: %s: %s',
            $envelope->getRoutingKey(),
            $exception::class,
            $exception->getMessage(),
        ));
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
