<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Helpers for a worker script launched by WorkerMaster. The master passes its
 * metadata as argv flags (not env), so everything reaches the worker through a
 * single channel — alongside the address and the consumer's own workerArgs. These
 * helpers read those flags back from $_SERVER['argv'].
 */
class Worker
{
    public const string MASTER_PID_ARG = '--sconcurMasterPid';

    public const string INDEX_ARG = '--sconcurWorkerIndex';

    /**
     * The master's pid (from the --sconcurMasterPid argv flag), or null when not
     * launched by a master (run standalone). Pass it to HttpServer(masterPid:) so
     * the server self-terminates once this master — its known parent — dies.
     */
    public static function masterPid(): ?int
    {
        $value = self::argValue(self::MASTER_PID_ARG);

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * The worker's slot index (0..N-1, from the --sconcurWorkerIndex argv flag), or
     * 0 when run standalone. Useful for jittering per-worker limits.
     */
    public static function index(): int
    {
        return (int) (self::argValue(self::INDEX_ARG) ?? '0');
    }

    /**
     * Returns the value of a `--name=value` argv flag, or null when absent.
     */
    protected static function argValue(string $name): ?string
    {
        $argv = $_SERVER['argv'] ?? [];

        if (!is_array($argv)) {
            return null;
        }

        $prefix = $name . '=';

        foreach ($argv as $argument) {
            if (is_string($argument) && str_starts_with($argument, $prefix)) {
                return substr($argument, strlen($prefix));
            }
        }

        return null;
    }
}
