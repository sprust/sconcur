<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

use Throwable;

/**
 * What the coroutines of one QueueConsumer share.
 *
 * No locking: coroutines are cooperative and single-threaded, so a counter only changes at
 * a suspension point of the coroutine that changes it.
 */
class ConsumerState
{
    protected bool $draining = false;

    protected int $finishedConsumers = 0;

    protected int $busyConsumers = 0;

    protected int $handledCount = 0;

    protected ?Throwable $consumerFailure = null;

    /**
     * Each consumer returns as soon as its current message is finished. One waiting for a
     * delivery never sees this — those end in the drain's second phase, see
     * QueueConsumer::superviseDrain().
     */
    public function startDraining(): void
    {
        $this->draining = true;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    /**
     * The first failure is kept: a pool whose consumers all died must not exit as if it had
     * finished its shift.
     */
    public function consumerFinished(?Throwable $failure = null): void
    {
        ++$this->finishedConsumers;

        $this->consumerFailure ??= $failure;
    }

    /**
     * Counted up rather than down from a live count, so the supervisor's first wakeup —
     * which can beat the consumers to their first line — reads 0 and not "none left".
     */
    public function finishedConsumers(): int
    {
        return $this->finishedConsumers;
    }

    /** The first failure that ended a consumer, if any did. */
    public function consumerFailure(): ?Throwable
    {
        return $this->consumerFailure;
    }

    public function messageStarted(): void
    {
        ++$this->busyConsumers;
    }

    /**
     * The handler returned or threw, and the runtime has settled the message. Counted only
     * here, so one the drain cut short is not reported as handled.
     */
    public function messageFinished(): void
    {
        --$this->busyConsumers;

        ++$this->handledCount;
    }

    /**
     * The coroutine holding the message was unwound, so it goes back to the broker
     * unsettled: the slot is freed without counting as handled.
     */
    public function messageAbandoned(): void
    {
        --$this->busyConsumers;
    }

    /**
     * Coroutines inside a handler or settling its message. The drain waits for this to
     * reach zero, which is why settling counts: one counted out with its acknowledgement
     * still in flight could be cut between the two.
     */
    public function busyConsumers(): int
    {
        return $this->busyConsumers;
    }

    public function handledCount(): int
    {
        return $this->handledCount;
    }
}
