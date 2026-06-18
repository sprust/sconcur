<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\InvalidConfigException;

/**
 * The master configuration, loaded from the --configPath JSON file. It holds the
 * WorkerMaster parameters plus a nested `server` object whose keys are translated
 * into the worker's argv flags (so the worker still receives everything via
 * arguments — the master is the only thing that reads a config file).
 *
 * Unspecified values fall back to the defaults below. `server.address` becomes the
 * worker's first positional argument; every other `server` entry becomes a
 * `--key=value` flag (booleans render as 1/0).
 */
readonly class MasterConfig
{
    /**
     * @param array<string, int|float|string|bool> $server     server params → worker argv flags
     * @param list<string>                         $phpArgs    interpreter flags for the worker
     * @param list<string>                         $workerArgs extra raw worker argv flags
     * @param array<string, string>                $env        extra env merged over the inherited one
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
        $workerScript = (string) ($data['workerScript'] ?? '');

        if ($workerScript === '') {
            throw new InvalidConfigException(
                message: 'config: "workerScript" is required',
            );
        }

        $restartPolicyValue = (string) ($data['restartPolicy'] ?? RestartPolicy::Always->value);
        $restartPolicy      = RestartPolicy::tryFrom($restartPolicyValue);

        if ($restartPolicy === null) {
            throw new InvalidConfigException(
                message: 'config: "restartPolicy" must be always|on-failure|never',
            );
        }

        $logDir = isset($data['logDir']) ? (string) $data['logDir'] : null;

        return new self(
            workerScript: $workerScript,
            runtimeDir: (string) ($data['runtimeDir'] ?? sys_get_temp_dir()),
            logDir: $logDir,
            name: (string) ($data['name'] ?? 'sconcur-http-server'),
            rotateDays: (int) ($data['rotateDays'] ?? 3),
            workerCount: (int) ($data['workerCount'] ?? 0),
            phpBinary: (string) ($data['phpBinary'] ?? PHP_BINARY),
            phpArgs: self::stringList($data['phpArgs'] ?? []),
            workerArgs: self::stringList($data['workerArgs'] ?? []),
            env: self::stringMap($data['env'] ?? []),
            restartPolicy: $restartPolicy,
            shutdownTimeoutMs: (int) ($data['shutdownTimeoutMs'] ?? 10_000),
            restartBackoffMs: (int) ($data['restartBackoffMs'] ?? 200),
            maxRestartBackoffMs: (int) ($data['maxRestartBackoffMs'] ?? 30_000),
            server: self::serverParams($data['server'] ?? []),
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

    /**
     * Builds the supervisor. The `server` object is expanded into the worker argv:
     * `address` is the first positional argument, every other entry a `--key=value`
     * flag; any extra `workerArgs` follow.
     */
    public function toWorkerMaster(): WorkerMaster
    {
        $address = isset($this->server['address']) ? (string) $this->server['address'] : null;

        $workerArgs = [];

        if ($address !== null && $address !== '') {
            $workerArgs[] = $address;
        }

        foreach ($this->server as $key => $value) {
            if ($key === 'address') {
                continue;
            }

            $workerArgs[] = '--' . $key . '=' . $this->scalarToArg($value);
        }

        foreach ($this->workerArgs as $workerArg) {
            $workerArgs[] = $workerArg;
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
            env: $this->env,
            restartPolicy: $this->restartPolicy,
            shutdownTimeoutMs: $this->shutdownTimeoutMs,
            restartBackoffMs: $this->restartBackoffMs,
            maxRestartBackoffMs: $this->maxRestartBackoffMs,
            address: $address,
        );
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
     * @return array<string, int|float|string|bool>
     *
     * @throws InvalidConfigException when present but not an object
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
            if (is_scalar($item)) {
                $params[(string) $key] = $item;
            }
        }

        return $params;
    }
}
