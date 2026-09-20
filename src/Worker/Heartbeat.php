<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * The worker's half of the liveness channel its master's watchdog listens on: a byte
 * written to an extra pipe whenever the PHP thread comes back to the scheduler.
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

    /** The one heartbeat of this process, kept because claiming it consumes the variable. */
    protected static ?self $claimed = null;

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
        // Asked for once per scheduler, but a process that rebuilds one asks again, and by
        // then the variable is gone — it was taken out of the environment below. Without
        // this the second scheduler would mark nothing and its healthy worker be killed.
        if (self::$claimed !== null) {
            return self::$claimed;
        }

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

        // Taken out of the environment once it has been claimed. The descriptor is
        // inherited by anything this worker spawns, and PHP cannot mark it close-on-exec;
        // a child that is itself a SConcur server would otherwise open the same pipe and
        // mark its parent alive, which is the one reading that must never be faked.
        // All three, because putenv() leaves $_ENV and $_SERVER as they were, and a child
        // spawned with either of them as its environment would still find the variable.
        putenv(self::FD_ENVIRONMENT_NAME);

        unset($_ENV[self::FD_ENVIRONMENT_NAME], $_SERVER[self::FD_ENVIRONMENT_NAME]);

        self::$claimed = new self(stream: $stream);

        return self::$claimed;
    }

    /**
     * Records that the PHP thread is still moving. Throttled to INTERVAL_MS, so it is an
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
