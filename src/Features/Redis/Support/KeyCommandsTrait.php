<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

/** Keys and their lifetimes. */
trait KeyCommandsTrait
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

    /** How many of the given keys were removed. */
    public function del(string ...$keys): int
    {
        return $keys === [] ? 0 : (int) $this->command('DEL', $keys);
    }

    /** DEL that frees the memory in the background. */
    public function unlink(string ...$keys): int
    {
        return $keys === [] ? 0 : (int) $this->command('UNLINK', $keys);
    }

    /** How many of the given keys exist; a key named twice counts twice, as in Redis. */
    public function exists(string ...$keys): int
    {
        return $keys === [] ? 0 : (int) $this->command('EXISTS', $keys);
    }

    public function expire(string $key, int $seconds): bool
    {
        return (int) $this->command('EXPIRE', [$key, $seconds]) === 1;
    }

    public function expireAt(string $key, int $unixTimeSeconds): bool
    {
        return (int) $this->command('EXPIREAT', [$key, $unixTimeSeconds]) === 1;
    }

    /**
     * Seconds left, or null when the key has no expiry and false when it does not exist —
     * Redis answers -1 and -2, which are easy to read as a duration by mistake.
     */
    public function ttl(string $key): int|null|false
    {
        $reply = (int) $this->command('TTL', [$key]);

        return match ($reply) {
            -1      => null,
            -2      => false,
            default => $reply,
        };
    }

    public function persist(string $key): bool
    {
        return (int) $this->command('PERSIST', [$key]) === 1;
    }

    public function rename(string $key, string $newKey): bool
    {
        return $this->command('RENAME', [$key, $newKey]) !== null;
    }

    /** The key's type: string, list, set, zset, hash, stream — or none. */
    public function type(string $key): string
    {
        return (string) $this->command('TYPE', [$key]);
    }

    public function randomKey(): ?string
    {
        $reply = $this->command('RANDOMKEY');

        return $reply === null ? null : (string) $reply;
    }
}
