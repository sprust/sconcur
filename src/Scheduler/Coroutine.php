<?php

declare(strict_types=1);

namespace SConcur\Scheduler;

use Fiber;
use SConcur\WaitGroup;

/**
 * A single coroutine tracked by the Scheduler: the fiber running a WaitGroup
 * callback plus the bookkeeping needed to route a task result back to it and to
 * report its completion to the owning group.
 */
final class Coroutine
{
    /**
     * @param int       $id          spl_object_id of the fiber
     * @param Fiber     $fiber       the running callback
     * @param WaitGroup $group       the group that owns (spawned) this coroutine
     * @param string    $callbackKey key returned by WaitGroup::add for this coroutine
     */
    public function __construct(
        public int $id,
        public Fiber $fiber,
        public WaitGroup $group,
        public string $callbackKey,
    ) {
    }
}
