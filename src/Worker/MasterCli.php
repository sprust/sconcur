<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\InvalidConfigException;
use Throwable;

/**
 * The universal master CLI behind bin/sconcur-server. Every command takes a
 * single --configPath pointing to a JSON master config (see MasterConfig); there are
 * no other flags. Commands: start (run the supervisor in the foreground), status
 * (report whether a master is running, via the lock), stop (remove the state file —
 * the master watches it and shuts the pool down gracefully) or reload (touch the
 * reload trigger file — the master rolls its workers one by one, zero-downtime with
 * SO_REUSEPORT).
 *
 * The consumer writes their worker script, points config.workerScript at it, and puts
 * the server params under config.server.
 *
 * Exit codes: 0 ok; 1 error (start failed at runtime, or reload timed out/failed);
 * 2 usage (missing/unknown command or invalid config); 3 not-running (`status`, or
 * `reload` when no master is running). `stop` is idempotent: it returns 0 whether or
 * not a master was running.
 */
class MasterCli
{
    public const int EXIT_OK          = 0;
    public const int EXIT_ERROR       = 1;
    public const int EXIT_USAGE       = 2;
    public const int EXIT_NOT_RUNNING = 3;

    protected const string CONFIG_PATH_FLAG = '--configPath=';

    protected const string GROUP_FLAG = '--group=';

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
        $command = $argv[1] ?? '';

        // Dispatch an empty/unknown command to the usage text before touching the
        // config, so `sconcur-server` with no args prints the command list instead of
        // a "--configPath is required" error.
        if (!in_array($command, ['start', 'status', 'stop', 'reload'], true)) {
            return $this->usage();
        }

        $arguments = array_slice($argv, 2);

        $configPath = $this->flag(
            args: $arguments,
            flag: self::CONFIG_PATH_FLAG,
        );

        $group = $this->flag(
            args: $arguments,
            flag: self::GROUP_FLAG,
        );

        $config = $this->loadConfig($configPath);

        if (!$config instanceof MasterConfig) {
            return $config;
        }

        if ($group !== '') {
            $known = $this->hasGroup(
                config: $config,
                name: $group,
            );

            if (!$known) {
                return $this->fail(
                    message: sprintf('unknown group "%s" in %s', $group, $configPath),
                    code: self::EXIT_USAGE,
                );
            }
        }

        return match ($command) {
            'start'  => $this->start($config),
            'status' => $this->status(
                config: $config,
                group: $group,
            ),
            'stop'   => $this->stop($config),
            'reload' => $this->reload(
                config: $config,
                configPath: $configPath,
                group: $group,
            ),
        };
    }

    protected function start(MasterConfig $config): int
    {
        try {
            return $config->toWorkerMaster()->run();
        } catch (Throwable $exception) {
            return $this->fail(
                message: $exception->getMessage(),
                code: self::EXIT_ERROR,
            );
        }
    }

    protected function status(MasterConfig $config, string $group = ''): int
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
            'running: pid=%d workers=%d groups=%d',
            $state->pid,
            $state->workerCount,
            count($state->groups),
        ));

        foreach ($state->groups as $reported) {
            if ($group !== '' && $reported->name !== $group) {
                continue;
            }

            $this->writeOut(sprintf(
                '  %s: workers=%d script=%s',
                $reported->name,
                $reported->workerCount,
                $reported->workerScript,
            ));
        }

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

        return $this->fail(
            message: 'stop timeout; master still running',
            code: self::EXIT_ERROR,
        );
    }

    /**
     * Requests a rolling restart of the running master's workers via the reload
     * trigger file, then waits until the master consumes it (deletes it once the roll
     * completes). No signal — and so no PID-reuse risk — is involved.
     */
    protected function reload(MasterConfig $config, string $configPath, string $group = ''): int
    {
        $lockPath = $this->lockPath($config);

        if (!$this->masterRunning($lockPath)) {
            $this->writeOut('not running');

            return self::EXIT_NOT_RUNNING;
        }

        // Absolute, because the master reads it from its own working directory and not
        // from the operator's. A relative path written as it was typed resolves to
        // nothing there, and the master would roll the workers onto the config it already
        // had while reporting a successful reload — the edit silently not applied.
        $resolvedPath = realpath($configPath);

        if ($resolvedPath === false) {
            return $this->fail(
                message: 'cannot resolve the config path: ' . $configPath,
                code: self::EXIT_ERROR,
            );
        }

        $reloadFile = $this->reloadFile($config);

        if (!$reloadFile->request(configPath: $resolvedPath, group: $group)) {
            return $this->fail('cannot write reload trigger: ' . $reloadFile->path(), self::EXIT_ERROR);
        }

        $timeoutMs = $this->reloadTimeoutMs(
            config: $config,
            group: $group,
        );

        $deadline = microtime(true) + $timeoutMs / 1000;

        // The master deletes the trigger once the rolling restart finishes; poll for it.
        while (microtime(true) < $deadline) {
            if (!$this->masterRunning($lockPath)) {
                return $this->fail(
                    message: 'master exited during reload',
                    code: self::EXIT_ERROR,
                );
            }

            if (!$reloadFile->requested()) {
                $this->writeOut('reloaded');

                return self::EXIT_OK;
            }

            usleep(100_000);
        }

        return $this->fail(
            message: 'reload timeout; master still rolling workers',
            code: self::EXIT_ERROR,
        );
    }

    /**
     * A generous bound on how long the rolling reload may take: each worker may drain
     * for up to the master's shutdownTimeoutMs before it is killed, and they roll one
     * at a time.
     */
    protected function reloadTimeoutMs(MasterConfig $config, string $group = ''): int
    {
        // Workers roll one at a time, so the wait is the sum of their drains rather than
        // the longest of them. A scoped reload waits for its group alone.
        foreach ($config->groups() as $configured) {
            if ($group !== '' && $configured->name === $group) {
                $workers = $configured->workerCount > 0 ? $configured->workerCount : Cpu::count();

                return $workers * ($configured->shutdownTimeoutMs + 2_000) + 5_000;
            }
        }

        return $config->totalWorkerCount() * ($config->maxShutdownTimeoutMs() + 2_000) + 5_000;
    }

    protected function reloadFile(MasterConfig $config): MasterReloadFile
    {
        return new MasterReloadFile(
            path: $config->runtimeDir() . '/' . $config->name() . '.reload',
        );
    }

    /**
     * Loads the config, or returns an exit code to propagate (usage when --configPath
     * is missing, error when the config is invalid).
     */
    protected function loadConfig(string $configPath): MasterConfig|int
    {
        if ($configPath === '') {
            return $this->fail(
                message: '--configPath=<file> is required',
                code: self::EXIT_USAGE,
            );
        }

        try {
            return MasterConfig::fromFile($configPath);
        } catch (InvalidConfigException $exception) {
            return $this->fail(
                message: $exception->getMessage(),
                code: self::EXIT_USAGE,
            );
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
    protected function flag(array $args, string $flag): string
    {
        $value = '';

        foreach ($args as $argument) {
            if (str_starts_with($argument, $flag)) {
                $value = substr($argument, strlen($flag));
            }
        }

        return $value;
    }

    protected function hasGroup(MasterConfig $config, string $name): bool
    {
        foreach ($config->groups() as $group) {
            if ($group->name === $name) {
                return true;
            }
        }

        return false;
    }

    protected function usage(): int
    {
        $this->writeErr(<<<USAGE
            Usage: sconcur-server <start|status|stop|reload> --configPath=FILE [--group=NAME]

              --configPath=FILE   JSON master config (required for every command)
              --group=NAME        narrow status or reload to one group

            The JSON holds the master-wide settings (runtimeDir, logDir, name,
            rotateDays, logTo, panelPort, adminToken) plus the defaults its groups
            inherit (phpBinary, phpArgs, env, restartPolicy, shutdownTimeoutMs,
            restartBackoffMs, maxRestartBackoffMs), and a "groups" list of pools. A
            group names its workerScript and workerCount, and its nested "server"
            object becomes the workers' "--key=value" argv flags (booleans render as
            1/0, a list or an object as JSON). Unspecified values use their defaults.

            start   run the supervisor (foreground)
            status  report whether a master is running (exit 0 running, 3 stopped/stale)
            stop    remove the state file (the stop signal) and wait for the master to exit
            reload  re-read the config and roll the workers onto it one by one
                    (zero-downtime restart), then wait for it to finish
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
