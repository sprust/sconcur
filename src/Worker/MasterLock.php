<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\MasterAlreadyRunningException;
use SConcur\Exceptions\Worker\RuntimePathException;

/**
 * Single-instance guard via an exclusive, non-blocking flock held for the master's
 * whole lifetime. The kernel releases the lock automatically when the holding
 * process dies (even on SIGKILL), so there is no stale-lock problem — unlike a bare
 * PID file. The lock file is intentionally never unlinked (avoids a create/lock race
 * with a competing master); an empty leftover file is harmless.
 */
class MasterLock
{
    /** @var resource|null */
    protected mixed $handle = null;

    public function __construct(
        protected string $path,
    ) {
    }

    /**
     * @throws MasterAlreadyRunningException another master already holds the lock
     * @throws RuntimePathException          the lock file cannot be opened
     */
    public function acquire(): void
    {
        // "e" → O_CLOEXEC: spawned workers must NOT inherit this fd, otherwise an
        // orphaned worker would keep the lock held after the master died (breaking the
        // single-instance guard and crash detection).
        $handle = fopen($this->path, 'ce');

        if ($handle === false) {
            throw new RuntimePathException(
                message: 'Cannot open master lock file: ' . $this->path,
            );
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new MasterAlreadyRunningException(
                message: 'Another master already holds the lock: ' . $this->path,
            );
        }

        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }
}
