<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * When the master respawns a worker that has exited.
 *
 * Default is Always: a long-lived server that exits — cleanly or not — should come
 * back. This is also what makes the HttpServer maxRequests feature work: a worker
 * exits with code 0 once it has handled its quota, and Always brings up a fresh
 * process. OnFailure would treat that clean exit as "done" and not restart.
 */
enum RestartPolicy: string
{
    /**
     * Decides whether a worker that just exited should be restarted.
     *
     * @param bool $cleanExit true when the worker exited with code 0 (not killed by
     *                        a signal and not a non-zero status)
     */
    public function shouldRestart(bool $cleanExit): bool
    {
        return match ($this) {
            self::Always    => true,
            self::OnFailure => !$cleanExit,
            self::Never     => false,
        };
    }
    case Always    = 'always';
    case OnFailure = 'on-failure';
    case Never     = 'never';
}
