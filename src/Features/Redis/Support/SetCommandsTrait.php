<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

/** Sets. */
trait SetCommandsTrait
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

    public function sAdd(string $key, string ...$members): int
    {
        return $members === []
            ? 0
            : (int) $this->command('SADD', array_merge([$key], $members));
    }

    public function sRem(string $key, string ...$members): int
    {
        return $members === []
            ? 0
            : (int) $this->command('SREM', array_merge([$key], $members));
    }

    /**
     * @return list<string>
     */
    public function sMembers(string $key): array
    {
        /** @var array<int, mixed> $reply */
        $reply = $this->command('SMEMBERS', [$key]);

        return array_map(static fn(mixed $item): string => (string) $item, array_values($reply));
    }

    public function sIsMember(string $key, string $member): bool
    {
        return (int) $this->command('SISMEMBER', [$key, $member]) === 1;
    }

    public function sCard(string $key): int
    {
        return (int) $this->command('SCARD', [$key]);
    }

    public function sPop(string $key): ?string
    {
        $reply = $this->command('SPOP', [$key]);

        return $reply === null ? null : (string) $reply;
    }
}
