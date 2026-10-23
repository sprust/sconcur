<?php

declare(strict_types=1);

namespace SConcur\Process;

/**
 * What a worker tells its master, one byte each on the heartbeat pipe the master opens for
 * every worker (ProcessControl::markHeartbeat()):
 *
 * - alive: the PHP thread came back to the scheduler. Written over and over, at most twice a
 *   second, and what the master's watchdog times;
 * - ready: the worker is up and doing its work — a server's serve loop is running on a bound
 *   socket. Written once, and what a rolling reload waits for before it stops the worker this
 *   one replaces;
 * - recycling: the worker is stopping because it reached its own limit (maxRequests,
 *   maxConnections, maxMessages). Written once, so the master brings up its replacement at
 *   once and does not count the exit as a failure.
 *
 * Alive exists because the telemetry snapshot cannot answer that question. Snapshots are
 * pushed by a loop inside the extension, on its own runtime thread, so a worker whose PHP
 * thread is frozen in a native call keeps reporting as if nothing happened. This mark is
 * written by the PHP thread itself, which is the thread the watchdog needs to know about.
 *
 * A pipe rather than a file: nothing survives the process, the master already reads the
 * worker's other two pipes on every supervision tick, and the kernel closes this one when the
 * worker dies. The master times the bytes by its own clock, so no timestamp has to cross the
 * process boundary.
 *
 * Rust: process::heartbeat::Mark in ext/src/process/heartbeat.rs.
 *
 * @internal see ProcessControl
 */
enum HeartbeatMarkEnum: int
{
    /** The PHP thread is still moving. */
    case Alive = 1;

    /** The worker is up and doing its work. */
    case Ready = 2;

    /** The worker is stopping because it reached its own limit, and wants replacing. */
    case Recycling = 3;

    /** The byte on the pipe, as the master reads it. */
    public function byte(): string
    {
        return chr($this->value);
    }
}
