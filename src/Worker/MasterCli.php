<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\InvalidConfigException;
use Throwable;

/**
 * The universal master CLI behind bin/sconcur-http-server. Every command takes a
 * single --configPath pointing to a JSON master config (see MasterConfig); there are
 * no other flags. Commands: start (run the supervisor in the foreground), status
 * (report whether a master is running, via the lock) or stop (remove the state file —
 * the master watches it and shuts the pool down gracefully).
 *
 * The consumer writes their worker script, points config.workerScript at it, and puts
 * the server params under config.server.
 *
 * Exit codes: 0 ok; 1 error; 2 usage; 3 not-running (for status/stop and guards).
 */
class MasterCli
{
    public const int EXIT_OK          = 0;
    public const int EXIT_ERROR       = 1;
    public const int EXIT_USAGE       = 2;
    public const int EXIT_NOT_RUNNING = 3;

    protected const string CONFIG_PATH_FLAG = '--configPath=';

    protected const int STOP_TIMEOUT_MS = 15_000;

    /** @var resource */
    protected mixed $stdout;

    /** @var resource */
    protected mixed $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(mixed $stdout = null, mixed $stderr = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * @param list<string> $argv full argv ($argv); argv[0] is the script path
     */
    public function run(array $argv): int
    {
        $command    = $argv[1] ?? '';
        $configPath = $this->configPath(array_slice($argv, 2));

        $config = $this->loadConfig($configPath);

        if (!$config instanceof MasterConfig) {
            return $config;
        }

        return match ($command) {
            'start'  => $this->start($config),
            'status' => $this->status($config),
            'stop'   => $this->stop($config),
            default  => $this->usage(),
        };
    }

    protected function start(MasterConfig $config): int
    {
        try {
            return $config->toWorkerMaster()->run();
        } catch (Throwable $exception) {
            return $this->fail($exception->getMessage(), self::EXIT_ERROR);
        }
    }

    protected function status(MasterConfig $config): int
    {
        // Liveness is decided by whether a master holds the lock, not by a pid in the
        // state file — the lock is released by the kernel only when the real master
        // dies, so this is immune to a stale state file and PID reuse.
        if (!$this->masterRunning($this->lockPath($config))) {
            $this->writeOut('stopped');

            return self::EXIT_NOT_RUNNING;
        }

        $state = $this->stateFile($config)->read();

        if ($state === null) {
            $this->writeOut('running');

            return self::EXIT_OK;
        }

        $this->writeOut(sprintf(
            'running: pid=%d workers=%d address=%s',
            $state->pid,
            $state->workerCount,
            $state->address ?? '-',
        ));

        return self::EXIT_OK;
    }

    protected function stop(MasterConfig $config): int
    {
        $lockPath = $this->lockPath($config);

        if (!$this->masterRunning($lockPath)) {
            $this->writeOut('not running');

            return self::EXIT_OK;
        }

        // Removing the state file is the stop signal the master watches for: it then
        // drains its workers and exits. No pid/signal needed (and so no PID-reuse risk).
        $statePath = $this->stateFile($config)->path();

        if (is_file($statePath)) {
            @unlink($statePath);
        }

        $deadline = microtime(true) + self::STOP_TIMEOUT_MS / 1000;

        // Wait until the lock is released — the kernel drops it the moment the master
        // process exits (even before it is reaped), so this never hangs on a zombie.
        while (microtime(true) < $deadline) {
            if (!$this->masterRunning($lockPath)) {
                $this->writeOut('stopped');

                return self::EXIT_OK;
            }

            usleep(100_000);
        }

        return $this->fail('stop timeout; master still running', self::EXIT_ERROR);
    }

    /**
     * Loads the config, or returns an exit code to propagate (usage when --configPath
     * is missing, error when the config is invalid).
     */
    protected function loadConfig(string $configPath): MasterConfig|int
    {
        if ($configPath === '') {
            return $this->fail('--configPath=<file> is required', self::EXIT_USAGE);
        }

        try {
            return MasterConfig::fromFile($configPath);
        } catch (InvalidConfigException $exception) {
            return $this->fail($exception->getMessage(), self::EXIT_USAGE);
        }
    }

    protected function stateFile(MasterConfig $config): MasterStateFile
    {
        return new MasterStateFile(
            path: $config->runtimeDir() . '/' . $config->name() . '-state.json',
        );
    }

    protected function lockPath(MasterConfig $config): string
    {
        return $config->runtimeDir() . '/' . $config->name() . '.lock';
    }

    /**
     * Whether a master holds the runtime lock. Tries to take the same exclusive,
     * non-blocking flock the master holds: failure means a live master owns it
     * (running); success means it is free (stopped) and is released immediately.
     * Immune to a stale state file and PID reuse — the kernel releases the lock only
     * when the holding process dies.
     *
     * @phpstan-impure result reflects external lock state and changes over time (the
     *                 stop loop polls it), so it must not be memoized across calls
     */
    protected function masterRunning(string $lockPath): bool
    {
        if (!is_file($lockPath)) {
            return false;
        }

        $handle = fopen($lockPath, 'ce');

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
     * Extracts the --configPath value from the remaining argv (last wins).
     *
     * @param list<string> $args
     */
    protected function configPath(array $args): string
    {
        $configPath = '';

        foreach ($args as $argument) {
            if (str_starts_with($argument, self::CONFIG_PATH_FLAG)) {
                $configPath = substr($argument, strlen(self::CONFIG_PATH_FLAG));
            }
        }

        return $configPath;
    }

    protected function usage(): int
    {
        $this->writeErr(<<<USAGE
            Usage: sconcur-http-server <start|status|stop> --configPath=FILE

              --configPath=FILE   JSON master config (required for every command)

            The JSON holds the WorkerMaster parameters (workerScript, workerCount,
            phpArgs, runtimeDir, logDir, name, rotateDays, restartPolicy,
            shutdownTimeoutMs, restartBackoffMs, maxRestartBackoffMs, env) plus a
            nested "server" object whose keys become the worker's argv flags
            ("address" is the worker's first positional argument). Unspecified values
            use their defaults.

            start   run the supervisor (foreground)
            status  report whether a master is running (exit 0 running, 3 stopped/stale)
            stop    remove the state file (the stop signal) and wait for the master to exit
            USAGE);

        return self::EXIT_USAGE;
    }

    protected function fail(string $message, int $code): int
    {
        $this->writeErr($message);

        return $code;
    }

    protected function writeOut(string $message): void
    {
        fwrite($this->stdout, $message . "\n");
    }

    protected function writeErr(string $message): void
    {
        fwrite($this->stderr, $message . "\n");
    }
}
