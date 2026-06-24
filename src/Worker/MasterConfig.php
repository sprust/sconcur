<?php

declare(strict_types=1);

namespace SConcur\Worker;

use ReflectionClass;
use ReflectionParameter;
use SConcur\Exceptions\Worker\InvalidConfigException;

/**
 * The master configuration, loaded from the --configPath JSON file. It holds the
 * WorkerMaster parameters plus a nested `server` object whose keys are translated
 * into the worker's argv flags (so the worker still receives everything via
 * arguments — the master is the only thing that reads a config file).
 *
 * Every `server` entry is forwarded verbatim as a `--key=value` flag (booleans
 * render as 1/0). The master is server-agnostic: it never inspects or whitelists the
 * keys, so the same supervisor drives any worker that parses `--key=value` argv.
 */
readonly class MasterConfig
{
    /**
     * @param array<string, int|float|string|bool> $server     server params → worker argv flags
     * @param list<string>                         $phpArgs    interpreter flags for the worker
     * @param list<string>                         $workerArgs extra raw worker argv flags
     * @param array<string, string>                $env        extra env merged over the inherited one
     * @param int                                  $panelPort  telemetry panel port (0 = telemetry off)
     * @param string                               $adminToken Bearer token gating the panel (required with panelPort)
     */
    public function __construct(
        protected string $workerScript,
        protected string $runtimeDir,
        protected ?string $logDir,
        protected string $name,
        protected int $rotateDays,
        protected int $workerCount,
        protected string $phpBinary,
        protected array $phpArgs,
        protected array $workerArgs,
        protected array $env,
        protected RestartPolicy $restartPolicy,
        protected int $shutdownTimeoutMs,
        protected int $restartBackoffMs,
        protected int $maxRestartBackoffMs,
        protected array $server,
        protected LogTarget $logTo,
        protected int $panelPort,
        protected string $adminToken,
    ) {
    }

    /**
     * @throws InvalidConfigException the file is missing/unreadable, not a JSON object,
     *                                or carries an invalid value
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidConfigException(
                message: 'Config file not found: ' . $path,
            );
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new InvalidConfigException(
                message: 'Cannot read config file: ' . $path,
            );
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new InvalidConfigException(
                message: 'Config file is not a JSON object: ' . $path,
            );
        }

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidConfigException a required value is missing or invalid
     */
    public static function fromArray(array $data): self
    {
        self::assertKnownKeys($data);

        $workerScript = (string) ($data['workerScript'] ?? '');

        if ($workerScript === '') {
            throw new InvalidConfigException(
                message: 'config: "workerScript" is required',
            );
        }

        $name = (string) ($data['name'] ?? 'sconcur-server');

        // The name is a path component (lock/state/log file prefix) and a glob pattern
        // for log pruning, so restrict it to a safe charset — no "/", no glob meta.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw new InvalidConfigException(
                message: 'config: "name" may contain only letters, digits, ".", "_" and "-"',
            );
        }

        $restartPolicyValue = (string) ($data['restartPolicy'] ?? RestartPolicy::Always->value);
        $restartPolicy      = RestartPolicy::tryFrom($restartPolicyValue);

        if ($restartPolicy === null) {
            throw new InvalidConfigException(
                message: 'config: "restartPolicy" must be always|on-failure|never',
            );
        }

        $logToValue = (string) ($data['logTo'] ?? LogTarget::File->value);
        $logTo      = LogTarget::tryFrom($logToValue);

        if ($logTo === null) {
            throw new InvalidConfigException(
                message: 'config: "logTo" must be file|stdout|both',
            );
        }

        $logDir = isset($data['logDir']) ? (string) $data['logDir'] : null;

        return new self(
            workerScript: $workerScript,
            runtimeDir: (string) ($data['runtimeDir'] ?? sys_get_temp_dir()),
            logDir: $logDir,
            name: $name,
            rotateDays: self::nonNegativeInt($data, 'rotateDays', 3),
            workerCount: (int) ($data['workerCount'] ?? 0),
            phpBinary: (string) ($data['phpBinary'] ?? PHP_BINARY),
            phpArgs: self::stringList($data['phpArgs'] ?? []),
            workerArgs: self::stringList($data['workerArgs'] ?? []),
            env: self::stringMap($data['env'] ?? []),
            restartPolicy: $restartPolicy,
            shutdownTimeoutMs: self::nonNegativeInt($data, 'shutdownTimeoutMs', 10_000),
            restartBackoffMs: self::nonNegativeInt($data, 'restartBackoffMs', 200),
            maxRestartBackoffMs: self::nonNegativeInt($data, 'maxRestartBackoffMs', 30_000),
            server: self::serverParams($data['server'] ?? []),
            logTo: $logTo,
            panelPort: self::nonNegativeInt($data, 'panelPort', 0),
            adminToken: (string) ($data['adminToken'] ?? ''),
        );
    }

    public function runtimeDir(): string
    {
        return $this->runtimeDir;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function workerCount(): int
    {
        return $this->workerCount;
    }

    public function shutdownTimeoutMs(): int
    {
        return $this->shutdownTimeoutMs;
    }

    /**
     * Builds the supervisor. Every `server` entry is expanded into a `--key=value`
     * worker argv flag (booleans render as 1/0); any extra raw `workerArgs` follow.
     */
    public function toWorkerMaster(): WorkerMaster
    {
        $workerArgs = [];

        foreach ($this->server as $key => $value) {
            $workerArgs[] = '--' . $key . '=' . $this->scalarToArg($value);
        }

        foreach ($this->workerArgs as $workerArg) {
            $workerArgs[] = $workerArg;
        }

        // Forward the pool scope to workers via env (not argv) so the server-agnostic
        // master does not feed a non-matching worker an unknown --flag:
        // HttpServer/SocketServer::fromArgs read it, others ignore it. The operator's
        // own env wins on a key collision (left operand of +).
        $env = $this->env + [
            'SCONCUR_SERVER_NAME' => $this->name,
        ];

        // Only point workers at the collector socket when telemetry is actually on
        // (panel port + token) — otherwise they would dial a socket nobody listens on
        // every interval. The master listens on this exact path (see WorkerMaster).
        if ($this->telemetryEnabled()) {
            $env += [
                'SCONCUR_TELEMETRY_SOCKET' => $this->runtimeDir . '/' . $this->name . '.telemetry.sock',
            ];
        }

        return new WorkerMaster(
            workerScript: $this->workerScript,
            runtimeDir: $this->runtimeDir,
            logDir: $this->logDir,
            name: $this->name,
            rotateDays: $this->rotateDays,
            workerCount: $this->workerCount,
            phpBinary: $this->phpBinary,
            phpArgs: $this->phpArgs,
            workerArgs: $workerArgs,
            env: $env,
            restartPolicy: $this->restartPolicy,
            shutdownTimeoutMs: $this->shutdownTimeoutMs,
            restartBackoffMs: $this->restartBackoffMs,
            maxRestartBackoffMs: $this->maxRestartBackoffMs,
            logTo: $this->logTo,
            panelPort: $this->panelPort,
            adminToken: $this->adminToken,
        );
    }

    protected function telemetryEnabled(): bool
    {
        return $this->panelPort > 0 && $this->adminToken !== '';
    }

    /**
     * Rejects unknown top-level keys (a typo like "wokerCount" would otherwise
     * silently fall back to its default). The set of valid keys is derived from the
     * constructor parameters by reflection, so it cannot drift from them — every
     * config key mirrors a constructor parameter by design.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidConfigException an unknown top-level key is present
     */
    protected static function assertKnownKeys(array $data): void
    {
        $knownKeys = array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            new ReflectionClass(self::class)->getConstructor()?->getParameters() ?? [],
        );

        $unknownKeys = array_diff(array_keys($data), $knownKeys);

        if ($unknownKeys !== []) {
            throw new InvalidConfigException(
                message: 'config: unknown key(s): ' . implode(', ', $unknownKeys),
            );
        }
    }

    /**
     * Reads an optional integer that must not be negative (timeouts, counts, day
     * retention — a negative value is always an operator mistake).
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidConfigException the value is present but negative
     */
    protected static function nonNegativeInt(array $data, string $key, int $default): int
    {
        $value = (int) ($data[$key] ?? $default);

        if ($value < 0) {
            throw new InvalidConfigException(
                message: sprintf('config: "%s" must be >= 0', $key),
            );
        }

        return $value;
    }

    protected function scalarToArg(int|float|string|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    protected static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    /**
     * @return array<string, string>
     */
    protected static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (is_scalar($item)) {
                $map[(string) $key] = (string) $item;
            }
        }

        return $map;
    }

    /**
     * Reads the `server` object. Every entry is forwarded to the worker as a
     * `--key=value` flag, so a non-scalar value cannot be expressed on argv and is a
     * config error rather than a silent drop — that keeps the pass-through total.
     *
     * @return array<string, int|float|string|bool>
     *
     * @throws InvalidConfigException when present but not an object, or any value is non-scalar
     */
    protected static function serverParams(mixed $value): array
    {
        if ($value === []) {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidConfigException(
                message: 'config: "server" must be an object of scalar values',
            );
        }

        $params = [];

        foreach ($value as $key => $item) {
            if (!is_scalar($item)) {
                throw new InvalidConfigException(
                    message: sprintf(
                        'config: "server.%s" must be a scalar (it is forwarded as a --%s=value worker flag)',
                        (string) $key,
                        (string) $key,
                    ),
                );
            }

            $params[(string) $key] = $item;
        }

        return $params;
    }
}
