<?php

declare(strict_types=1);

namespace SConcur\Scheduler;

/**
 * Suspend values the FiberPool's worker loop uses to talk to the Scheduler.
 *
 * An enum case is a process-wide singleton, so the Scheduler's `===` check is an
 * identity comparison: unlike a string sentinel, handler code cannot produce
 * this value by accident — or out of request data — and be mistaken for a
 * finished job. Getting that wrong is not a cosmetic bug: the Scheduler would
 * clean up a coroutine that is still parked mid-job and hand its live fiber to
 * the next request. The check costs the same as the string compare it replaces
 * (both resolve to a pointer comparison), so the guarantee is free.
 */
enum FiberPoolSignal
{
    /**
     * The worker loop parked itself: the previous job finished. This is the
     * completion signal on the spawn path, replacing Fiber::isTerminated() —
     * a pooled fiber never terminates.
     */
    case Idle;
}
