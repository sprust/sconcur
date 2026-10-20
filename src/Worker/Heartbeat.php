<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * The worker's half of the channel its master listens on: an extra pipe the worker's PHP
 * thread writes one byte to whenever it has something to say. Three things, one byte each:
 *
 * - alive (MARK_ALIVE): the PHP thread came back to the scheduler. Written over and over,
 *   at most every INTERVAL_MS, and what the master's watchdog times;
 * - ready (MARK_READY): the worker is up and doing its work — a server's serve loop is
 *   running on a bound socket. Written once, and what a rolling reload waits for before it
 *   stops the worker this one replaces;
 * - recycling (MARK_RECYCLING): the worker is stopping because it reached its own limit
 *   (maxRequests, maxConnections, maxMessages). Written once, so the master brings up its
 *   replacement at once and does not count the exit as a failure.
 *
 * Alive exists because the telemetry snapshot cannot answer that question. Snapshots are
 * pushed by a loop inside the extension, on its own runtime thread, so a worker whose PHP
 * thread is frozen in a native call keeps reporting as if nothing happened. This mark is
 * written by the PHP thread itself, which is the thread the watchdog needs to know about.
 *
 * A pipe rather than a file: nothing survives the process, the master already reads the
 * worker's other two pipes on every supervision tick, and the kernel closes this one when
 * the worker dies. The master times the bytes by its own clock, so no timestamp has to
 * cross the process boundary.
 *
 * A worker started without a master finds no descriptor in its environment and writes
 * nothing.
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

    /** The PHP thread is still moving. */
    public const string MARK_ALIVE = "\x01";

    /** The worker is up and doing its work. */
    public const string MARK_READY = "\x02";

    /** The worker is stopping because it reached its own limit, and wants replacing. */
    public const string MARK_RECYCLING = "\x03";

    /**
     * The shortest gap between two writes. The serve loop passes its top many times a
     * second on a busy server, and the watchdog's threshold is in tens of seconds, so
     * writing more often would only cost syscalls.
     */
    protected const int INTERVAL_MS = 500;

    /** The one heartbeat of this process, kept because claiming it consumes the variable. */
    protected static ?self $claimed = null;

    /** Whether current() has looked: once it has, the answer stands, none included. */
    protected static bool $resolved = false;

    protected int $lastTouchNs = 0;

    protected bool $readySent = false;

    /** @param resource $stream */
    public function __construct(protected mixed $stream)
    {
    }

    /**
     * The heartbeat this process writes, or null when there is none to write: looked for
     * in the environment the first time, unless a fork handed one over (adopt()). Asked
     * where it is written rather than kept by whoever writes it, so a scheduler built
     * before its process was forked into a worker writes the worker's, not the master's.
     */
    public static function current(): ?self
    {
        if (!self::$resolved) {
            self::$resolved = true;

            self::fromEnvironment();
        }

        return self::$claimed;
    }

    /**
     * The heartbeat this process should write, or null when it runs without a master (no
     * descriptor, nothing to write to).
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
     * Claims a liveness pipe handed over directly rather than named in the environment:
     * the write end a forked worker gets from its fork. No variable to take out of the
     * environment, then, and no descriptor number for a child of the worker to find —
     * nothing it starts can mark this worker alive. Null for a fork that got none: the
     * worker then writes nothing, whatever its master's process had claimed.
     *
     * @param resource|null $stream
     */
    public static function adopt(mixed $stream): ?self
    {
        self::$resolved = true;
        self::$claimed  = null;

        if ($stream === null) {
            return null;
        }

        stream_set_blocking($stream, false);

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

        @fwrite($this->stream, self::MARK_ALIVE);
    }

    /**
     * Tells the master the worker is up. Once per process: a worker that serves twice (a
     * script that starts a second server after the first one ended) was ready the first
     * time, and the master has nothing more to learn from it.
     */
    public function ready(): void
    {
        if ($this->readySent) {
            return;
        }

        $this->readySent = true;

        @fwrite($this->stream, self::MARK_READY);
    }

    /** Tells the master the worker is stopping because it reached its own limit. */
    public function recycling(): void
    {
        @fwrite($this->stream, self::MARK_RECYCLING);
    }
}
