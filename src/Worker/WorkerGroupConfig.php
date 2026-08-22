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
        public array $server,
    ) {
    }

    /**
     * Reads one group, falling back to the master-wide defaults for what it does not
     * say itself.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidConfigException a required value is missing or invalid
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

        return new self(
            name: $name,
            workerScript: $workerScript,
            workerCount: $workerCount,
            phpBinary: (string) ($data['phpBinary'] ?? $defaults->phpBinary),
            phpArgs: isset($data['phpArgs']) ? MasterConfig::stringList($data['phpArgs']) : $defaults->phpArgs,
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
            server: MasterConfig::serverParams($data['server'] ?? null, $name),
        );
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
