<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Helpers for a worker script launched by WorkerMaster — reading the env the master
 * injects and checking that the master is still alive.
 */
class Worker
{
    /**
     * The master's pid (from SCONCUR_MASTER_PID), or null when not launched by a
     * master (run standalone).
     */
    public static function masterPid(): ?int
    {
        $value = getenv('SCONCUR_MASTER_PID');

        if ($value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public static function index(): int
    {
        return (int) (getenv('SCONCUR_WORKER_INDEX') ?: '0');
    }

    public static function count(): int
    {
        return (int) (getenv('SCONCUR_WORKER_COUNT') ?: '1');
    }
}
