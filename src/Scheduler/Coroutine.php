<?php

declare(strict_types=1);

namespace SConcur\Scheduler;

use Fiber;
use SConcur\WaitGroup;

/**
 * A single coroutine tracked by the Scheduler: the fiber running a callback plus
 * the bookkeeping needed to route a task result back to it and to report its
 * completion to the owning group.
 *
 * A coroutine usually belongs to a WaitGroup, which collects its result. A
 * spawned coroutine (Scheduler::spawn — e.g. one HTTP request handler) has no
 * group: it is fire-and-forget, its return value is not collected, so $group is
 * null.
 */
class Coroutine
{
    /**
     * The flow/task keys of the result this coroutine is currently parked on,
     * set by Scheduler::dispatchPendingTask at push time and cleared on resume.
     * They validate the frame-carried ownerFiberId before resuming: spl_object_id
     * is reused once a fiber is freed, so a stale result whose owner id now
     * belongs to a different coroutine must not resume it out of turn.
     */
    public string $awaitedFlowKey = '';
    public string $awaitedTaskKey = '';

    /**
     * Whether any awaited push/next ran on this coroutine's own flow. A spawned
     * coroutine that only fired detached (fire-and-forget) pushes never created
     * its flow on the Go side, so the Scheduler skips the stopFlow crossing.
     */
    public bool $flowUsed = false;

    /**
     * @param int            $id          spl_object_id of the fiber
     * @param Fiber          $fiber       the running callback
     * @param WaitGroup|null $group       the group that owns this coroutine, or null when spawned standalone
     * @param string         $callbackKey key returned by WaitGroup::add for this coroutine (empty when spawned)
     * @param string         $flowKey     per-coroutine flow key; set for spawned coroutines so the Scheduler can
     *                                    stop the flow when they finish (group coroutines are cleaned by the group)
     */
    public function __construct(
        public readonly int $id,
        public readonly Fiber $fiber,
        public readonly ?WaitGroup $group,
        public readonly string $callbackKey,
        public readonly string $flowKey = '',
    ) {
    }
}
