<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Who this worker is: the group it serves, its slot and the master that forked it. Handed
 * to the group's role, and kept for the life of the worker, where what is built inside the
 * role finds it without being told (WorkerContext::current()): a server's orphan check
 * watches the master, for one.
 */
class WorkerContext
{
    protected static ?self $current = null;

    /**
     * @param string $group     the name of the group the worker serves
     * @param int    $slot      its slot in the group, from 0
     * @param int    $masterPid the master that forked it; once it is no longer this
     *                          process's parent, the master is gone
     */
    public function __construct(
        public readonly string $group,
        public readonly int $slot,
        public readonly int $masterPid,
    ) {
    }

    /** The worker this process is, or null in a process no master forked. */
    public static function current(): ?self
    {
        return static::$current;
    }

    /**
     * Makes this the worker the process is. Called once, by ForkedWorker, before the role
     * runs.
     *
     * @internal
     */
    public static function enter(self $context): void
    {
        static::$current = $context;
    }
}
