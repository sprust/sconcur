<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Exceptions\Redis\RedisCommandException;

/** Lua scripts. */
trait ScriptCommandsTrait
{
    /**
     * @param list<mixed> $arguments
     */
    abstract public function command(
        string $name,
        array $arguments = [],
        ?bool $blocking = null,
        ?int $timeoutMs = null,
    ): mixed;

    /**
     * EVAL. The keys are passed separately from the arguments because Redis needs to know
     * how many of them there are, and getting that number wrong is the classic way to
     * write a script that breaks in a cluster.
     *
     * @param list<string> $keys
     * @param list<mixed>  $arguments
     */
    public function eval(string $script, array $keys = [], array $arguments = []): mixed
    {
        return $this->command(
            'EVAL',
            array_merge([$script, count($keys)], $keys, $arguments),
        );
    }

    /**
     * EVALSHA, falling back to EVAL when the server does not have the script cached.
     *
     * The fallback is the reason this method exists: a NOSCRIPT after a server restart is
     * expected, not exceptional, and every caller would otherwise write the same catch.
     *
     * @param list<string> $keys
     * @param list<mixed>  $arguments
     */
    public function evalSha(string $sha1, string $script, array $keys = [], array $arguments = []): mixed
    {
        try {
            return $this->command(
                'EVALSHA',
                array_merge([$sha1, count($keys)], $keys, $arguments),
            );
        } catch (RedisCommandException $exception) {
            if ($exception->errorCode !== 'NOSCRIPT') {
                throw $exception;
            }

            return $this->eval(script: $script, keys: $keys, arguments: $arguments);
        }
    }

    /** Loads a script and returns its sha1. */
    public function scriptLoad(string $script): string
    {
        return (string) $this->command('SCRIPT', ['LOAD', $script]);
    }
}
