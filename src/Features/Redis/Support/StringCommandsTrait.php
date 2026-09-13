<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;

/**
 * Strings and counters.
 *
 * A method exists here where it shapes the reply or assembles options a caller would
 * otherwise have to spell out in the right order. Everything else is a command() call away
 * and no worse for it.
 */
trait StringCommandsTrait
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

    /** The value, or null when the key does not exist. */
    public function get(string $key): ?string
    {
        $reply = $this->command('GET', [$key]);

        return $reply === null ? null : (string) $reply;
    }

    /**
     * SET with its options assembled here, because their order matters and NX and XX are
     * mutually exclusive.
     *
     * Returns false when NX or XX kept the value from being set — that is Redis answering
     * with nil, not a failure.
     */
    public function set(
        string $key,
        string $value,
        ?int $ttlSeconds = null,
        ?int $ttlMs = null,
        bool $keepTtl = false,
        bool $ifNotExists = false,
        bool $ifExists = false,
    ): bool {
        if ($ttlSeconds !== null && $ttlMs !== null) {
            throw new InvalidRedisArgumentException(
                message: 'Pass either ttlSeconds or ttlMs, not both: SET takes one expiry, '
                    . 'and both would build a command the server refuses.',
            );
        }

        $arguments = [$key, $value];

        if ($ttlSeconds !== null) {
            $arguments[] = 'EX';
            $arguments[] = $ttlSeconds;
        }

        if ($ttlMs !== null) {
            $arguments[] = 'PX';
            $arguments[] = $ttlMs;
        }

        if ($keepTtl) {
            $arguments[] = 'KEEPTTL';
        }

        if ($ifNotExists && $ifExists) {
            throw new InvalidRedisArgumentException(
                message: 'Pass either ifNotExists or ifExists, not both: NX and XX are mutually '
                    . 'exclusive, and both would build a command the server refuses.',
            );
        }

        if ($ifNotExists) {
            $arguments[] = 'NX';
        }

        if ($ifExists) {
            $arguments[] = 'XX';
        }

        return $this->command('SET', $arguments) !== null;
    }

    public function setNx(string $key, string $value): bool
    {
        return (int) $this->command('SETNX', [$key, $value]) === 1;
    }

    public function getSet(string $key, string $value): ?string
    {
        $reply = $this->command('GETSET', [$key, $value]);

        return $reply === null ? null : (string) $reply;
    }

    public function getDel(string $key): ?string
    {
        $reply = $this->command('GETDEL', [$key]);

        return $reply === null ? null : (string) $reply;
    }

    /**
     * MGET, keyed by the keys asked for rather than by position, so a caller does not have
     * to line the two lists up itself. A missing key is null.
     *
     * A key asked for twice appears once, because a map has one entry per key.
     *
     * A key that looks like an integer comes back as an int key: PHP casts those, and
     * no array can hold "1" as a string. Cast a key back with (string) before passing
     * it to another method.
     *
     * @param array<array-key, string> $keys
     *
     * @return array<int|string, string|null>
     */
    public function mGet(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        // The reply is positional and dense, because Arguments::encode re-indexes what
        // goes on the wire. The keys need the same treatment before they can be lined
        // up with it: array_filter and array_unique both leave gaps, and a gap would
        // read a key the server answered for as missing.
        $keys = array_values($keys);

        /** @var list<string|null> $reply */
        $reply = $this->command('MGET', $keys);

        $values = [];

        foreach ($keys as $position => $key) {
            $value        = $reply[$position] ?? null;
            $values[$key] = $value === null ? null : (string) $value;
        }

        return $values;
    }

    /**
     * A key that looks like an integer arrives as an int key — see mGet; the cast
     * below takes it back to the bytes the server stores.
     *
     * @param array<array-key, string|int|float> $values
     */
    public function mSet(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        $arguments = [];

        foreach ($values as $key => $value) {
            $arguments[] = (string) $key;
            $arguments[] = $value;
        }

        return $this->command('MSET', $arguments) !== null;
    }

    public function incrBy(string $key, int $by = 1): int
    {
        return (int) $this->command('INCRBY', [$key, $by]);
    }

    public function decrBy(string $key, int $by = 1): int
    {
        return (int) $this->command('DECRBY', [$key, $by]);
    }

    /** INCRBYFLOAT answers with the new value as a string; it is parsed here. */
    public function incrByFloat(string $key, float $by): float
    {
        return (float) $this->command('INCRBYFLOAT', [$key, $by]);
    }

    public function append(string $key, string $value): int
    {
        return (int) $this->command('APPEND', [$key, $value]);
    }

    public function strLen(string $key): int
    {
        return (int) $this->command('STRLEN', [$key]);
    }
}
