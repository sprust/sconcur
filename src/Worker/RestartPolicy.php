<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * When the master respawns a worker that has exited.
 *
 * Default is Always: a long-lived server that exits — cleanly or not — should come
 * back. A worker that leaves because it reached its own limit (maxRequests,
 * maxConnections, maxMessages) asked to be replaced, and is brought back under
 * OnFailure too; only Never lets it go.
 */
enum RestartPolicy: string
{
    case Always    = 'always';
    case OnFailure = 'on-failure';
    case Never     = 'never';

    /**
     * Decides whether a worker that just exited should be restarted.
     *
     * @param bool $cleanExit    true when the worker exited with code 0 (not killed by
     *                           a signal and not a non-zero status)
     * @param bool $reachedLimit true when the worker said it was leaving because it
     *                           reached its own limit
     */
    public function shouldRestart(bool $cleanExit, bool $reachedLimit = false): bool
    {
        return match ($this) {
            self::Always    => true,
            self::OnFailure => $reachedLimit || !$cleanExit,
            self::Never     => false,
        };
    }
}
