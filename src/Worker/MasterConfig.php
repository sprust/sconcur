<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\InvalidConfigException;

/**
 * The master configuration, loaded from the --configPath JSON file. The top level
 * holds what belongs to the master as a whole — where it keeps its lock, state and
 * journal, and its telemetry panel — plus the defaults its groups inherit. The pools
 * themselves are the `groups` list.
 *
 * A group is a worker script and how many processes of it to keep up. They need not be
 * alike: an HTTP server pool and three queue-consumer pools run under one supervisor,
 * one lock and one journal.
 *
 * The master stays worker-agnostic. A group's `server` entries are forwarded to its
 * workers' argv verbatim (see WorkerGroupConfig::argumentFlags) — the master never
 * inspects or whitelists the keys, so the same supervisor drives any worker that parses
 * `--key=value`.
 */
readonly class MasterConfig
{
    /** The keys the top level accepts. */
    protected const array KNOWN_KEYS = [
        'runtimeDir',
        'logDir',
        'name',
        'rotateDays',
        'logTo',
        'panelPort',
        'adminToken',
        'phpBinary',
        'phpArgs',
        'env',
        'restartPolicy',
        'shutdownTimeoutMs',
        'restartBackoffMs',
        'maxRestartBackoffMs',
        'groups',
    ];

    /**
     * The keys that used to live at the top level and now belong to a group. Named
     * explicitly so an old config gets told where its settings went instead of a bare
     * "unknown key".
     */
    protected const array MOVED_TO_GROUP_KEYS = [
        'workerScript',
        'workerCount',
        'workerArgs',
        'server',
    ];

    /**
     * @param list<WorkerGroupConfig> $groups     the pools this master supervises
     * @param int                     $panelPort  telemetry panel port (0 = telemetry off)
     * @param string                  $adminToken Bearer token gating the panel (required with panelPort)
     */
    public function __construct(
        protected string $runtimeDir,
        protected ?string $logDir,
        protected string $name,
        protected int $rotateDays,
        protected LogTarget $logTo,
        protected int $panelPort,
        protected string $adminToken,
        protected array $groups,
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

        $name = (string) ($data['name'] ?? 'sconcur-server');

        // The name is a path component (lock/state/log file prefix) and a glob pattern
        // for log pruning, so restrict it to a safe charset — no "/", no glob meta.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw new InvalidConfigException(
                message: 'config: "name" may contain only letters, digits, ".", "_" and "-"',
            );
        }

        $logTo = LogTarget::tryFrom((string) ($data['logTo'] ?? LogTarget::File->value));

        if ($logTo === null) {
            throw new InvalidConfigException(
                message: 'config: "logTo" must be file|stdout|both',
            );
        }

        $restartPolicy = RestartPolicy::tryFrom(
            (string) ($data['restartPolicy'] ?? RestartPolicy::Always->value),
        );

        if ($restartPolicy === null) {
            throw new InvalidConfigException(
                message: 'config: "restartPolicy" must be always|on-failure|never',
            );
        }

        $defaults = new MasterDefaults(
            phpBinary: (string) ($data['phpBinary'] ?? PHP_BINARY),
            phpArgs: self::stringList($data['phpArgs'] ?? []),
            env: self::stringMap($data['env'] ?? []),
            restartPolicy: $restartPolicy,
            shutdownTimeoutMs: self::nonNegativeInt($data, 'shutdownTimeoutMs', 10_000),
            restartBackoffMs: self::nonNegativeInt($data, 'restartBackoffMs', 200),
            maxRestartBackoffMs: self::nonNegativeInt($data, 'maxRestartBackoffMs', 30_000),
        );

        return new self(
            runtimeDir: (string) ($data['runtimeDir'] ?? sys_get_temp_dir()),
            logDir: isset($data['logDir']) ? (string) $data['logDir'] : null,
            name: $name,
            rotateDays: self::nonNegativeInt($data, 'rotateDays', 3),
            logTo: $logTo,
            panelPort: self::nonNegativeInt($data, 'panelPort', 0),
            adminToken: (string) ($data['adminToken'] ?? ''),
            groups: self::parseGroups($data, $defaults),
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
     * @return list<WorkerGroupConfig>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /** Every worker of every group, with 0 resolved to the core count. */
    public function totalWorkerCount(): int
    {
        $total = 0;

        foreach ($this->groups as $group) {
            $total += $group->workerCount > 0 ? $group->workerCount : Cpu::count();
        }

        return $total;
    }

    /** The longest a single worker may take to drain, over all groups. */
    public function maxShutdownTimeoutMs(): int
    {
        $timeoutMs = 0;

        foreach ($this->groups as $group) {
            $timeoutMs = max($timeoutMs, $group->shutdownTimeoutMs);
        }

        return $timeoutMs;
    }

    /**
     * Builds the supervisor.
     */
    public function toWorkerMaster(): WorkerMaster
    {
        return new WorkerMaster(
            runtimeDir: $this->runtimeDir,
            logDir: $this->logDir,
            name: $this->name,
            rotateDays: $this->rotateDays,
            groups: $this->withRuntimeEnvironment(),
            logTo: $this->logTo,
            panelPort: $this->panelPort,
            adminToken: $this->adminToken,
        );
    }

    /**
     * Reads an optional integer that must not be negative (timeouts, counts, day
     * retention — a negative value is always an operator mistake).
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidConfigException the value is present but negative
     */
    public static function nonNegativeInt(array $data, string $key, int $default, string $groupName = ''): int
    {
        $value = (int) ($data[$key] ?? $default);

        if ($value < 0) {
            throw new InvalidConfigException(
                message: $groupName === ''
                    ? sprintf('config: "%s" must be >= 0', $key)
                    : sprintf('config: group "%s": "%s" must be >= 0', $groupName, $key),
            );
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
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
    public static function stringMap(mixed $value): array
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
     * Reads a group's `server` object. A scalar is forwarded as `--key=value`; a list or
     * an object is forwarded as JSON in that same value, which is how a worker takes a
     * structured setting (the queue list of a consumer, say) through argv. Nothing is
     * dropped silently — the pass-through is total.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidConfigException when present but not an object
     */
    public static function serverParams(mixed $value, string $groupName): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidConfigException(
                message: sprintf('config: group "%s": "server" must be an object', $groupName),
            );
        }

        $params = [];

        foreach ($value as $key => $item) {
            $params[(string) $key] = $item;
        }

        return $params;
    }

    /**
     * The groups with the pool scope added to their environment. It travels via env
     * rather than argv so the worker-agnostic master does not feed a non-matching
     * worker an unknown --flag: the bundled servers and QueueConsumer read it, anything
     * else ignores it. A group's own env wins on a collision.
     *
     * @return list<WorkerGroupConfig>
     */
    protected function withRuntimeEnvironment(): array
    {
        $shared = ['SCONCUR_SERVER_NAME' => $this->name];

        // Only point workers at the collector socket when telemetry is actually on
        // (panel port + token) — otherwise they would dial a socket nobody listens on
        // every interval. The master listens on this exact path (see WorkerMaster).
        if ($this->telemetryEnabled()) {
            $shared['SCONCUR_TELEMETRY_SOCKET'] = $this->runtimeDir . '/' . $this->name . '.telemetry.sock';
        }

        $groups = [];

        foreach ($this->groups as $group) {
            $groups[] = new WorkerGroupConfig(
                name: $group->name,
                workerScript: $group->workerScript,
                workerCount: $group->workerCount,
                phpBinary: $group->phpBinary,
                phpArgs: $group->phpArgs,
                workerArgs: $group->workerArgs,
                env: $group->env + $shared,
                restartPolicy: $group->restartPolicy,
                shutdownTimeoutMs: $group->shutdownTimeoutMs,
                restartBackoffMs: $group->restartBackoffMs,
                maxRestartBackoffMs: $group->maxRestartBackoffMs,
                server: $group->server,
            );
        }

        return $groups;
    }

    protected function telemetryEnabled(): bool
    {
        return $this->panelPort > 0 && $this->adminToken !== '';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<WorkerGroupConfig>
     *
     * @throws InvalidConfigException
     */
    protected static function parseGroups(array $data, MasterDefaults $defaults): array
    {
        $raw = $data['groups'] ?? null;

        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new InvalidConfigException(
                message: 'config: "groups" must be a non-empty list of pool objects',
            );
        }

        $groups = [];
        $names  = [];

        foreach ($raw as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidConfigException(
                    message: sprintf('config: groups[%d] must be an object', $index),
                );
            }

            /** @var array<string, mixed> $entry */
            $group = WorkerGroupConfig::fromArray(data: $entry, defaults: $defaults, index: $index);

            if (isset($names[$group->name])) {
                throw new InvalidConfigException(
                    message: sprintf('config: group "%s" is defined twice', $group->name),
                );
            }

            $names[$group->name] = true;

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Rejects unknown top-level keys (a typo like "wokerCount" would otherwise silently
     * fall back to its default), and points the keys that moved into a group at their
     * new home rather than calling them unknown.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidConfigException an unknown top-level key is present
     */
    protected static function assertKnownKeys(array $data): void
    {
        $moved = array_intersect(array_keys($data), self::MOVED_TO_GROUP_KEYS);

        if ($moved !== []) {
            throw new InvalidConfigException(
                message: 'config: ' . implode(', ', $moved)
                    . ' belong to a group now — move them into an entry of "groups"',
            );
        }

        $unknownKeys = array_diff(array_keys($data), self::KNOWN_KEYS);

        if ($unknownKeys !== []) {
            throw new InvalidConfigException(
                message: 'config: unknown key(s): ' . implode(', ', $unknownKeys),
            );
        }
    }
}
