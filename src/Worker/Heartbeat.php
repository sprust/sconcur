<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * The worker's half of the liveness channel its master's watchdog listens on: a byte
 * written to an extra pipe from the top of the serve loop (Scheduler::serve).
 *
 * It exists because the telemetry snapshot cannot answer this question. Snapshots are
 * pushed by a loop inside the extension, on its own runtime thread, so a worker whose
 * PHP thread is frozen in a native call keeps reporting as if nothing happened. This
 * mark is written by the PHP thread itself, which is the thread the watchdog needs to
 * know about.
 *
 * A pipe rather than a file: nothing survives the process, the master already reads the
 * worker's other two pipes on every supervision tick, and the kernel closes this one
 * when the worker dies. What travels is one byte — the content carries no meaning, the
 * arrival is the whole message, so the master times it by its own clock and no timestamp
 * has to cross the process boundary.
 *
 * A worker started without a master, or under one whose watchdog is off, finds no
 * descriptor in its environment and writes nothing.
 */
class Heartbeat
{
    /**
     * The environment variable naming the descriptor the master opened — env rather than
     * argv, for the same reason the telemetry socket travels that way: the master
     * supervises any worker script and must not feed one an argv flag it does not know.
     */
    public const string FD_ENVIRONMENT_NAME = 'SCONCUR_HEARTBEAT_FD';

    /** The descriptor the master attaches the pipe to, past stdin/stdout/stderr. */
    public const int FD = 3;

    /**
     * The shortest gap between two writes. The serve loop passes its top many times a
     * second on a busy server, and the watchdog's threshold is in tens of seconds, so
     * writing more often would only cost syscalls.
     */
    protected const int INTERVAL_MS = 500;

    protected int $lastTouchNs = 0;

    /** @param resource $stream */
    public function __construct(protected mixed $stream)
    {
    }

    /**
     * The heartbeat this process should write, or null when it runs without a master or
     * under one whose watchdog is off (no descriptor, nothing to write to).
     */
    public static function fromEnvironment(): ?self
    {
        $descriptor = getenv(self::FD_ENVIRONMENT_NAME);

        if (!is_string($descriptor) || !ctype_digit($descriptor)) {
            return null;
        }

        $stream = @fopen('php://fd/' . $descriptor, 'w');

        if ($stream === false) {
            return null;
        }

        // Non-blocking on purpose: a master that stopped reading must slow nothing down.
        // A full pipe means the master is in trouble, not this worker, and a short write
        // here is simply dropped.
        stream_set_blocking($stream, false);

        return new self(stream: $stream);
    }

    /**
     * Records that the serve loop is still turning. Throttled to INTERVAL_MS, so it is an
     * hrtime comparison on all but every few hundredth call.
     */
    public function touch(): void
    {
        $nowNs = hrtime(true);

        if ($this->lastTouchNs !== 0 && ($nowNs - $this->lastTouchNs) < (self::INTERVAL_MS * 1_000_000)) {
            return;
        }

        $this->lastTouchNs = $nowNs;

        @fwrite($this->stream, "\x01");
    }
}
