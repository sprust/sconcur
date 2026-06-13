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
final class Coroutine
{
    /**
     * @param int            $id          spl_object_id of the fiber
     * @param Fiber          $fiber       the running callback
     * @param WaitGroup|null $group       the group that owns this coroutine, or null when spawned standalone
     * @param string         $callbackKey key returned by WaitGroup::add for this coroutine (empty when spawned)
     * @param string         $flowKey     per-coroutine flow key; set for spawned coroutines so the Scheduler can
     *                                    stop the flow when they finish (group coroutines are cleaned by the group)
     */
    public function __construct(
        public int $id,
        public Fiber $fiber,
        public ?WaitGroup $group,
        public string $callbackKey,
        public string $flowKey = '',
    ) {
    }
}
