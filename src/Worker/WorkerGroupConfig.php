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
        'launch',
        'listen',
        'server',
    ];

    /**
     * @param array<string, mixed>  $server     worker parameters, expanded into argv flags
     * @param list<string>          $phpArgs    interpreter flags for this group's workers
     * @param list<string>          $workerArgs extra raw worker argv flags
     * @param array<string, string> $env        extra env merged over the inherited one
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
        public LaunchMode $launch = LaunchMode::Exec,
        public string $listen = '',
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

        if ($launch === LaunchMode::Fork) {
            static::assertForkable(
                name: $name,
                phpBinary: $phpBinary,
                phpArgs: $phpArgs,
            );
        }

        $server = MasterConfig::serverParams($data['server'] ?? null, $name);
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
                $data,
                'shutdownTimeoutMs',
                $defaults->shutdownTimeoutMs,
                $name,
            ),
            restartBackoffMs: MasterConfig::nonNegativeInt(
                $data,
                'restartBackoffMs',
                $defaults->restartBackoffMs,
                $name,
            ),
            maxRestartBackoffMs: MasterConfig::nonNegativeInt(
                $data,
                'maxRestartBackoffMs',
                $defaults->maxRestartBackoffMs,
                $name,
            ),
            watchdogTimeoutMs: MasterConfig::watchdogTimeoutMs(
                $data,
                $defaults->watchdogTimeoutMs,
                $name,
            ),
            server: $server,
            launch: $launch,
            listen: $listen,
        );
    }

    /**
     * The group's phpArgs as the ini settings they make, for a worker launched by fork:
     * [directive, value] pairs in the order given, so a later one wins as a later -d does.
     * Each `-d` takes either the next argument or its own tail (`-dmemory_limit=1G`); a
     * flag without a value sets the directive to "1", as php does. A pair rather than a map,
     * because `extension` may come more than once.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function iniSettings(): array
    {
        return static::parseIniFlags($this->phpArgs) ?? [];
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

    /**
     * Refuses what a fork group cannot honour, as far as the config alone tells: it runs on
     * the master's interpreter, so a php binary of its own is out, and its phpArgs can only
     * be ini settings. Whether each setting can be applied at runtime depends on the master
     * process, and is checked there (WorkerMaster::assertLaunchable).
     *
     * @param list<string> $phpArgs
     */
    protected static function assertForkable(string $name, string $phpBinary, array $phpArgs): void
    {
        if ($phpBinary !== PHP_BINARY) {
            throw new InvalidConfigException(
                message: sprintf(
                    'config: group "%s" is launched by fork, so it runs on the master\'s own php;'
                    . ' "phpBinary" needs launch=exec',
                    $name,
                ),
            );
        }

        if (static::parseIniFlags($phpArgs) === null) {
            throw new InvalidConfigException(
                message: sprintf(
                    'config: group "%s" is launched by fork, so its phpArgs can only set ini values'
                    . ' (-d name=value); %s needs launch=exec',
                    $name,
                    json_encode($phpArgs, JSON_UNESCAPED_SLASHES),
                ),
            );
        }
    }

    /**
     * @param list<string> $phpArgs
     *
     * @return list<array{0: string, 1: string}>|null null when an argument is not an ini
     *                                                setting
     */
    protected static function parseIniFlags(array $phpArgs): ?array
    {
        $settings = [];

        for ($index = 0; $index < count($phpArgs); ++$index) {
            $argument = $phpArgs[$index];

            if ($argument === '-d') {
                $argument = $phpArgs[++$index] ?? null;

                if ($argument === null) {
                    return null;
                }
            } elseif (str_starts_with($argument, '-d') && strlen($argument) > 2) {
                $argument = substr($argument, 2);
            } else {
                return null;
            }

            [$directive, $value] = array_pad(explode('=', $argument, 2), 2, '1');

            if ($directive === '') {
                return null;
            }

            $settings[] = [
                $directive,
                $value,
            ];
        }

        return $settings;
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
