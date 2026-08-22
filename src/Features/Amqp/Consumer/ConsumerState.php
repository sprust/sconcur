<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

/**
 * What the coroutines of one QueueConsumer share: whether the worker is draining, how
 * many consumers are still live, how many are mid-message, and what has been handled.
 *
 * Plain mutable state with no locking, which is the whole environment here: coroutines
 * are cooperative and single-threaded, so a counter only changes at a suspension point
 * of the coroutine that changes it.
 */
class ConsumerState
{
    protected bool $draining = false;

    protected int $startedConsumers = 0;

    protected int $liveConsumers = 0;

    protected int $busyConsumers = 0;

    protected int $handledCount = 0;

    protected int $failedCount = 0;

    /**
     * Stops the consumers: each returns as soon as the message it is on is finished.
     * A consumer waiting for a delivery does not see this — ending those is the
     * second phase of the drain, see QueueConsumer::superviseDrain().
     */
    public function startDraining(): void
    {
        $this->draining = true;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    public function consumerStarted(): void
    {
        ++$this->startedConsumers;

        ++$this->liveConsumers;
    }

    public function consumerFinished(): void
    {
        --$this->liveConsumers;
    }

    public function liveConsumers(): int
    {
        return $this->liveConsumers;
    }

    /**
     * How many consumers have ever started. Told apart from the live count so "none
     * left" is not confused with "none up yet": the supervisor wakes for the first time
     * while the consumers are still opening their channels.
     */
    public function startedConsumers(): int
    {
        return $this->startedConsumers;
    }

    public function messageStarted(): void
    {
        ++$this->busyConsumers;
    }

    /**
     * One message left the handler, whether it returned or threw. A failure is
     * counted, not swallowed: the worker's own report says how the run went.
     */
    public function messageFinished(bool $failed): void
    {
        --$this->busyConsumers;

        ++$this->handledCount;

        if ($failed) {
            ++$this->failedCount;
        }
    }

    /** How many coroutines are inside a handler right now. */
    public function busyConsumers(): int
    {
        return $this->busyConsumers;
    }

    public function handledCount(): int
    {
        return $this->handledCount;
    }

    public function failedCount(): int
    {
        return $this->failedCount;
    }
}
