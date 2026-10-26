<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * A running master as another process sees it, by the runtime directory and name it was
 * started with: whether it runs, what it reports, and how to stop it. Neither takes a
 * signal or a pid, so neither can reach a stranger that inherited a recycled pid.
 *
 * Whether it runs is whether a process holds its lock: the kernel lets the lock go only
 * when the holder dies, so a stale state file or a reused pid cannot fake it. Stopping it
 * is removing its state file, which the master watches for and answers by draining its
 * workers and leaving.
 */
class MasterControl
{
    protected const int POLL_INTERVAL_US = 100_000;

    public function __construct(
        protected string $runtimeDir,
        protected string $name = WorkerMaster::DEFAULT_NAME,
    ) {
    }

    /**
     * Whether a master holds the lock. Tries to take the same exclusive, non-blocking flock
     * the master holds: failure means a live master owns it; success means it is free, and
     * it is let go at once.
     *
     * @phpstan-impure the answer changes over time, and stop() polls it
     */
    public function isRunning(): bool
    {
        $path = $this->lockPath();

        if (!is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'ce');

        if ($handle === false) {
            return false;
        }

        $acquired = flock($handle, LOCK_EX | LOCK_NB);

        if ($acquired) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return !$acquired;
    }

    /**
     * What the running master reports of itself, or null when none runs or it wrote nothing
     * yet.
     *
     * @phpstan-impure the answer changes as the master writes its state
     */
    public function state(): ?MasterState
    {
        if (!$this->isRunning()) {
            return null;
        }

        return $this->stateFile()->read();
    }

    /**
     * Asks the master to stop and waits for it to be gone. True once no master runs —
     * straight away when none did — and false when it is still running at the deadline.
     */
    public function stop(int $timeoutMs = 15_000): bool
    {
        if (!$this->isRunning()) {
            return true;
        }

        $this->stateFile()->clear();

        $deadline = microtime(true) + $timeoutMs / 1000;

        // The kernel lets the lock go the moment the master exits, before it is reaped, so
        // this never waits on a zombie.
        while (microtime(true) < $deadline) {
            if (!$this->isRunning()) {
                return true;
            }

            usleep(self::POLL_INTERVAL_US);
        }

        return !$this->isRunning();
    }

    public function lockPath(): string
    {
        return $this->runtimeDir . '/' . $this->name . '.lock';
    }

    public function stateFilePath(): string
    {
        return $this->runtimeDir . '/' . $this->name . '-state.json';
    }

    protected function stateFile(): MasterStateFile
    {
        return new MasterStateFile(path: $this->stateFilePath());
    }
}
