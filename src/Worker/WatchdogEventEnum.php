<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * What the watchdog did to a worker, as reported to the master's watchdog handler.
 *
 * Three moments rather than one, because they mean different things to whoever is
 * watching: the first says a worker stopped answering, the second that it did not even
 * take a SIGTERM, the third that the kernel is holding a process the master cannot get
 * rid of.
 */
enum WatchdogEventEnum: string
{
    /** The worker stopped marking itself alive and has been sent SIGTERM. */
    case HeartbeatLost = 'heartbeat-lost';

    /** It did not exit within shutdownTimeoutMs of that, and has been sent SIGKILL. */
    case KillEscalated = 'kill-escalated';

    /** It is still there after the SIGKILL — uninterruptible I/O, and the slot stays down. */
    case KillSurvived = 'kill-survived';
}
