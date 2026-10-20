<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * A worker that has given up its slot and is finishing before it exits: one a rolling
 * reload replaced, one that reached its own limit after its replacement was started, or
 * a replacement the reload gave up on. Its output is still logged and it is still reaped;
 * past its deadline it is killed.
 */
class DrainingWorker
{
    /** Set once the SIGKILL that follows an overstayed deadline has been sent. */
    public bool $killSent = false;

    /**
     * @param int    $index      the slot it held, for the journal
     * @param int    $deadlineNs hrtime past which it is killed
     * @param string $reason     why it is leaving, as the journal says it
     */
    public function __construct(
        public readonly WorkerProcess $process,
        public readonly int $index,
        public readonly int $deadlineNs,
        public readonly string $reason,
    ) {
    }
}
