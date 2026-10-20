<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\InvalidConfigException;

/**
 * One pool inside a master: a worker script, how many processes of it to keep up, and
 * the arguments they get. A master runs several, and they need not be alike — an HTTP
 * server pool and three queue-consumer pools live under one supervisor, one lock and
 * one journal.
 *
 * A group says nothing about what its workers do. `server` is forwarded to their argv
 * as it stands (see argumentFlags), which is what keeps the master usable by any
 * worker script that reads `--key=value`.
 */
readonly class WorkerGroupConfig
{
    /** The keys a group accepts; anything else is a typo rather than a setting. */
    protected const array KNOWN_KEYS = [
        'name',
        'workerScript',
        'workerCount',
        'phpBinary',
        'phpArgs',
        'workerArgs',
        'env',
        'restartPolicy',
        'shutdownTimeoutMs',
        'restartBackoffMs',
        'maxRestartBackoffMs',
        'watchdogTimeoutMs',
        'readyTimeoutMs',
        'accessLog',
        'launch',
        'listen',
        'bootstrap',
        'server',
    ];

    /**
     * @param array<string, mixed>  $server     worker parameters, expanded into argv flags
     * @param list<string>          $phpArgs    interpreter flags for this group's workers
     * @param list<string>          $workerArgs extra raw worker argv flags
     * @param array<string, string> $env        extra env merged over the inherited one
     * @param string                $bootstrap  a file the group's fork server loads once per generation, in
     *                                          the global scope, before it forks the workers (fork only)
     * @param AccessLogTarget       $accessLog  where the workers write their access lines
     */
    public function __construct(
        public string $name,
        public string $workerScript,
        public int $workerCount,
        public string $phpBinary,
        public array $phpArgs,
        public array $workerArgs,
        public array $env,
        public RestartPolicy $restartPolicy,
        public int $shutdownTimeoutMs,
        public int $restartBackoffMs,
        public int $maxRestartBackoffMs,
        public int $watchdogTimeoutMs,
        public array $server,
        public LaunchMode $launch = LaunchMode::Fork,
        public string $listen = '',
        public int $readyTimeoutMs = MasterConfig::DEFAULT_READY_TIMEOUT_MS,
        public string $bootstrap = '',
        public AccessLogTarget $accessLog = AccessLogTarget::Stdout,
    ) {
    }

    /**
     * Reads one group, falling back to the master-wide defaults for what it does not
     * say itself.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, MasterDefaults $defaults, int $index): self
    {
        $unknown = array_diff(array_keys($data), self::KNOWN_KEYS);

        if ($unknown !== []) {
            throw new InvalidConfigException(
                message: sprintf(
                    'config: groups[%d] has unknown key(s): %s',
                    $index,
                    implode(', ', array_map(strval(...), $unknown)),
                ),
            );
        }

        $name = (string) ($data['name'] ?? '');

        if ($name === '') {
            throw new InvalidConfigException(
                message: sprintf('config: groups[%d] requires a "name"', $index),
            );
        }

        // The name reaches the journal and the --group flag of the CLI, so it is kept to
        // the same charset the master's own name is.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw new InvalidConfigException(
                message: sprintf(
                    'config: groups[%d] name "%s" may contain only letters, digits, ".", "_" and "-"',
                    $index,
                    $name,
                ),
            );
        }

        $workerScript = (string) ($data['workerScript'] ?? '');

        if ($workerScript === '') {
            throw new InvalidConfigException(
                message: sprintf('config: group "%s" requires a "workerScript"', $name),
            );
        }

        $workerCount = (int) ($data['workerCount'] ?? 0);

        if ($workerCount < 0) {
            throw new InvalidConfigException(
                message: sprintf('config: group "%s" has a negative "workerCount"', $name),
            );
        }

        $restartPolicy = RestartPolicy::tryFrom(
            (string) ($data['restartPolicy'] ?? $defaults->restartPolicy->value),
        );

        if ($restartPolicy === null) {
            throw new InvalidConfigException(
                message: sprintf('config: group "%s" restartPolicy must be always|on-failure|never', $name),
            );
        }

        $launch = LaunchMode::tryFrom((string) ($data['launch'] ?? $defaults->launch->value));

        if ($launch === null) {
            throw new InvalidConfigException(
                message: sprintf('config: group "%s" launch must be exec|fork', $name),
            );
        }

        $phpBinary = (string) ($data['phpBinary'] ?? $defaults->phpBinary);
        $phpArgs   = isset($data['phpArgs']) ? MasterConfig::stringList($data['phpArgs']) : $defaults->phpArgs;
        $bootstrap = (string) ($data['bootstrap'] ?? '');

        // Loaded by the fork server a fork group's workers are copies of; an exec'd worker is a php
        // of its own and loads whatever it needs in its script.
        if ($bootstrap !== '' && $launch !== LaunchMode::Fork) {
            throw new InvalidConfigException(
                message: sprintf('config: group "%s" has "bootstrap", which needs launch=fork', $name),
            );
        }

        $server = MasterConfig::serverParams(
            value: $data['server'] ?? null,
            groupName: $name,
        );
        $listen = (string) ($data['listen'] ?? '');

        // One socket the master binds and a socket each worker binds for itself are two
        // ways of sharing a port; a worker told both would bind its own and leave the
        // master's unused.
        if ($listen !== '' && (($server['reusePort'] ?? false) || isset($server['listenFd']))) {
            throw new InvalidConfigException(
                message: sprintf(
                    'config: group "%s" has "listen", so its workers accept on the master\'s socket;'
                    . ' drop "reusePort" and "listenFd" from its "server"',
                    $name,
                ),
            );
        }

        return new self(
            name: $name,
            workerScript: $workerScript,
            workerCount: $workerCount,
            phpBinary: $phpBinary,
            phpArgs: $phpArgs,
            workerArgs: MasterConfig::stringList($data['workerArgs'] ?? []),
            env: array_merge($defaults->env, MasterConfig::stringMap($data['env'] ?? [])),
            restartPolicy: $restartPolicy,
            shutdownTimeoutMs: MasterConfig::nonNegativeInt(
                data: $data,
                key: 'shutdownTimeoutMs',
                default: $defaults->shutdownTimeoutMs,
                groupName: $name,
            ),
            restartBackoffMs: MasterConfig::nonNegativeInt(
                data: $data,
                key: 'restartBackoffMs',
                default: $defaults->restartBackoffMs,
                groupName: $name,
            ),
            maxRestartBackoffMs: MasterConfig::nonNegativeInt(
                data: $data,
                key: 'maxRestartBackoffMs',
                default: $defaults->maxRestartBackoffMs,
                groupName: $name,
            ),
            watchdogTimeoutMs: MasterConfig::watchdogTimeoutMs(
                data: $data,
                default: $defaults->watchdogTimeoutMs,
                groupName: $name,
            ),
            server: $server,
            launch: $launch,
            listen: $listen,
            readyTimeoutMs: MasterConfig::readyTimeoutMs(
                data: $data,
                default: $defaults->readyTimeoutMs,
                groupName: $name,
            ),
            bootstrap: $bootstrap,
            accessLog: MasterConfig::accessLog(
                data: $data,
                default: $defaults->accessLog,
                groupName: $name,
            ),
        );
    }

    /**
     * Reads what toArray() wrote: every setting there and already resolved, so nothing falls
     * back to a default. How the fork server takes its group from the master. A key that is
     * missing means the fork server runs another release of this library than the master
     * that wrote it, and is refused rather than guessed.
     *
     * @param array<string, mixed> $data
     */
    public static function fromResolvedArray(array $data): self
    {
        $name    = (string) ($data['name'] ?? '');
        $missing = array_diff(self::KNOWN_KEYS, array_keys($data));

        if ($missing !== []) {
            throw new InvalidConfigException(
                message: sprintf(
                    'group "%s" was handed over without %s; the master and the fork server run'
                    . ' different releases of the library, restart the master',
                    $name,
                    implode(', ', $missing),
                ),
            );
        }

        $restartPolicy = RestartPolicy::tryFrom((string) $data['restartPolicy']);
        $launch        = LaunchMode::tryFrom((string) $data['launch']);
        $accessLog     = AccessLogTarget::tryFrom((string) $data['accessLog']);

        if ($restartPolicy === null || $launch === null || $accessLog === null) {
            throw new InvalidConfigException(
                message: sprintf(
                    'group "%s" was handed over with an unknown restartPolicy, launch or accessLog',
                    $name,
                ),
            );
        }

        return new self(
            name: $name,
            workerScript: (string) $data['workerScript'],
            workerCount: (int) $data['workerCount'],
            phpBinary: (string) $data['phpBinary'],
            phpArgs: MasterConfig::stringList($data['phpArgs']),
            workerArgs: MasterConfig::stringList($data['workerArgs']),
            env: MasterConfig::stringMap($data['env']),
            restartPolicy: $restartPolicy,
            shutdownTimeoutMs: (int) $data['shutdownTimeoutMs'],
            restartBackoffMs: (int) $data['restartBackoffMs'],
            maxRestartBackoffMs: (int) $data['maxRestartBackoffMs'],
            watchdogTimeoutMs: (int) $data['watchdogTimeoutMs'],
            server: MasterConfig::serverParams(
                value: $data['server'],
                groupName: $name,
            ),
            launch: $launch,
            listen: (string) $data['listen'],
            readyTimeoutMs: (int) $data['readyTimeoutMs'],
            bootstrap: (string) $data['bootstrap'],
            accessLog: $accessLog,
        );
    }

    /** How many workers the group keeps: workerCount, or one per core when that is 0. */
    public function resolvedWorkerCount(): int
    {
        return $this->workerCount > 0 ? $this->workerCount : Cpu::count();
    }

    /**
     * How long to wait before a restart that follows this many fast failures in a row: not
     * at all after none, then restartBackoffMs, doubled with each further one, up to
     * maxRestartBackoffMs. Keeps a crash loop from spinning.
     */
    public function backoffMs(int $consecutiveFastFails): int
    {
        if ($consecutiveFastFails <= 0) {
            return 0;
        }

        return (int) min(
            $this->restartBackoffMs * (2 ** ($consecutiveFastFails - 1)),
            $this->maxRestartBackoffMs,
        );
    }

    /**
     * Every setting, defaults resolved, in the shape fromResolvedArray() reads: how the master
     * hands a group to the fork server it starts for it, which is a php process of its own.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'                => $this->name,
            'workerScript'        => $this->workerScript,
            'workerCount'         => $this->workerCount,
            'phpBinary'           => $this->phpBinary,
            'phpArgs'             => $this->phpArgs,
            'workerArgs'          => $this->workerArgs,
            'env'                 => (object) $this->env,
            'restartPolicy'       => $this->restartPolicy->value,
            'shutdownTimeoutMs'   => $this->shutdownTimeoutMs,
            'restartBackoffMs'    => $this->restartBackoffMs,
            'maxRestartBackoffMs' => $this->maxRestartBackoffMs,
            'watchdogTimeoutMs'   => $this->watchdogTimeoutMs,
            'readyTimeoutMs'      => $this->readyTimeoutMs,
            'launch'              => $this->launch->value,
            'listen'              => $this->listen,
            'bootstrap'           => $this->bootstrap,
            'accessLog'           => $this->accessLog->value,
            'server'              => (object) $this->server,
        ];
    }

    /**
     * The `server` block as worker argv. A scalar travels as it is (a boolean as 1/0);
     * anything structured travels as JSON, because argv carries strings and there is no
     * shell on the way — WorkerProcess passes the command as an array, so quotes and
     * spaces inside a value are just characters.
     *
     * The master still does not know what any of it means. It only knows that a list or
     * an object has one obvious string form.
     *
     * @return list<string>
     */
    public function argumentFlags(): array
    {
        $flags = [];

        foreach ($this->server as $key => $value) {
            $flags[] = '--' . $key . '=' . static::flagValue($value);
        }

        return $flags;
    }

    protected static function flagValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
