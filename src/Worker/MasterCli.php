<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Throwable;

/**
 * The universal master CLI behind bin/sconcur-http-server. Parses argv and runs one
 * of: start (run the supervisor in the foreground), status (report whether a master
 * is running, via the lock) or stop (remove the state file — the master watches it
 * and shuts the pool down gracefully).
 *
 * The consumer only writes their worker script and points --worker at it.
 *
 * Exit codes: 0 ok; 1 error; 2 usage; 3 not-running (for status/stop and guards).
 */
class MasterCli
{
    public const int EXIT_OK          = 0;
    public const int EXIT_ERROR       = 1;
    public const int EXIT_USAGE       = 2;
    public const int EXIT_NOT_RUNNING = 3;

    protected const string DEFAULT_NAME = 'sconcur-http-server';

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
        $options = $this->parseOptions(array_slice($argv, 2));

        return match ($command) {
            'start'  => $this->start($options),
            'status' => $this->status($options),
            'stop'   => $this->stop($options),
            default  => $this->usage(),
        };
    }

    /**
     * @param array{scalar: array<string, string>, repeated: array<string, list<string>>} $options
     */
    protected function start(array $options): int
    {
        $scalar   = $options['scalar'];
        $repeated = $options['repeated'];

        $workerScript = $scalar['workerScript'] ?? '';

        if ($workerScript === '') {
            return $this->fail('start: --workerScript=<path> is required', self::EXIT_USAGE);
        }

        $policy = RestartPolicy::tryFrom($scalar['restartPolicy'] ?? RestartPolicy::Always->value);

        if ($policy === null) {
            return $this->fail('start: --restartPolicy must be always|on-failure|never', self::EXIT_USAGE);
        }

        $address = ($scalar['address'] ?? '') !== '' ? $scalar['address'] : null;

        $workerArgs = $address !== null ? [$address] : [];
        $workerArgs = [...$workerArgs, ...$repeated['workerArgs']];

        $master = new WorkerMaster(
            workerScript: $workerScript,
            runtimeDir: $scalar['runtimeDir'] ?? sys_get_temp_dir(),
            logDir: $scalar['logDir'] ?? null,
            name: $scalar['name'] ?? self::DEFAULT_NAME,
            rotateDays: (int) ($scalar['rotateDays'] ?? 3),
            workerCount: (int) ($scalar['workerCount'] ?? 0),
            phpBinary: $scalar['phpBinary'] ?? PHP_BINARY,
            phpArgs: $repeated['phpArgs'],
            workerArgs: $workerArgs,
            restartPolicy: $policy,
            shutdownTimeoutMs: (int) ($scalar['shutdownTimeoutMs'] ?? 10_000),
            restartBackoffMs: (int) ($scalar['restartBackoffMs'] ?? 200),
            maxRestartBackoffMs: (int) ($scalar['maxRestartBackoffMs'] ?? 30_000),
            address: $address,
        );

        try {
            return $master->run();
        } catch (Throwable $exception) {
            return $this->fail($exception->getMessage(), self::EXIT_ERROR);
        }
    }

    /**
     * @param array{scalar: array<string, string>, repeated: array<string, list<string>>} $options
     */
    protected function status(array $options): int
    {
        // Liveness is decided by whether a master holds the lock, not by a pid in the
        // state file — the lock is released by the kernel only when the real master
        // dies, so this is immune to a stale state file and PID reuse.
        if (!$this->masterRunning($this->lockPath($options))) {
            $this->writeOut('stopped');

            return self::EXIT_NOT_RUNNING;
        }

        $state = $this->stateFile($options)->read();

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

    /**
     * @param array{scalar: array<string, string>, repeated: array<string, list<string>>} $options
     */
    protected function stop(array $options): int
    {
        $lockPath = $this->lockPath($options);

        if (!$this->masterRunning($lockPath)) {
            $this->writeOut('not running');

            return self::EXIT_OK;
        }

        // Removing the state file is the stop signal the master watches for: it then
        // drains its workers and exits. No pid/signal needed (and so no PID-reuse risk).
        $statePath = $this->stateFile($options)->path();

        if (is_file($statePath)) {
            @unlink($statePath);
        }

        $timeoutSeconds = (int) ($options['scalar']['timeoutMs'] ?? 15_000) / 1000;
        $deadline       = microtime(true) + $timeoutSeconds;

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
     * @param array{scalar: array<string, string>, repeated: array<string, list<string>>} $options
     */
    protected function stateFile(array $options): MasterStateFile
    {
        return new MasterStateFile(
            path: $this->runtimeDir($options) . '/' . $this->name($options) . '-state.json',
        );
    }

    /**
     * @param array{scalar: array<string, string>, repeated: array<string, list<string>>} $options
     */
    protected function lockPath(array $options): string
    {
        return $this->runtimeDir($options) . '/' . $this->name($options) . '.lock';
    }

    /**
     * @param array{scalar: array<string, string>, repeated: array<string, list<string>>} $options
     */
    protected function runtimeDir(array $options): string
    {
        return $options['scalar']['runtimeDir'] ?? sys_get_temp_dir();
    }

    /**
     * @param array{scalar: array<string, string>, repeated: array<string, list<string>>} $options
     */
    protected function name(array $options): string
    {
        return $options['scalar']['name'] ?? self::DEFAULT_NAME;
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
     * Parses `--key=value` arguments. Option names match the WorkerMaster constructor
     * parameters verbatim. phpArgs and workerArgs are repeatable (collected into
     * lists); everything else is a single scalar (last wins).
     *
     * @param list<string> $args
     *
     * @return array{scalar: array<string, string>, repeated: array<string, list<string>>}
     */
    protected function parseOptions(array $args): array
    {
        $scalar = [];

        /** @var array<string, list<string>> $repeated */
        $repeated = [
            'phpArgs'    => [],
            'workerArgs' => [],
        ];

        foreach ($args as $argument) {
            if (!str_starts_with($argument, '--')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');

            if (array_key_exists($name, $repeated)) {
                $repeated[$name][] = $value;

                continue;
            }

            $scalar[$name] = $value;
        }

        return [
            'scalar'   => $scalar,
            'repeated' => $repeated,
        ];
    }

    protected function usage(): int
    {
        $this->writeErr(<<<USAGE
            Usage: sconcur-http-server <start|status|stop> [options]

            Option names match the WorkerMaster constructor parameters.

            start  run the supervisor (foreground)
              --workerScript=PATH        worker script (required)
              --workerCount=N            worker count (default: CPU cores)
              --address=HOST:PORT        passed to the worker argv and recorded in state
              --phpBinary=PATH           interpreter (default: current PHP_BINARY)
              --phpArgs=ARG              extra interpreter flag, repeatable (e.g. -d extension=...)
              --workerArgs=ARG           extra worker argv, repeatable
              --runtimeDir=DIR           lock + state dir (default: temp dir)
              --logDir=DIR               log dir (default: runtimeDir)
              --name=NAME                log/state prefix (default: sconcur-http-server)
              --rotateDays=N             days of logs to keep (default: 3)
              --restartPolicy=POLICY     always|on-failure|never (default: always)
              --shutdownTimeoutMs=N      drain timeout before SIGKILL (default: 10000)
              --restartBackoffMs=N       crash-loop backoff base (default: 200)
              --maxRestartBackoffMs=N    crash-loop backoff cap (default: 30000)

            status  report whether a master is running (exit 0 running, 3 stopped/stale)
              --runtimeDir=DIR --name=NAME

            stop  remove the state file (the stop signal) and wait for the master to exit
              --runtimeDir=DIR --name=NAME --timeoutMs=N
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
