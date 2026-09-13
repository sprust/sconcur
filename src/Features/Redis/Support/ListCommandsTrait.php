<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

/**
 * Lists, including the blocking pops.
 *
 * A blocking command runs on a connection of its own in the extension, so waiting on a
 * queue does not hold up the commands of every other coroutine. The task deadline is
 * raised to cover the wait — see Connection::blockingDeadlineMs.
 */
trait ListCommandsTrait
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

    abstract public function blockingDeadlineMs(float $timeoutSeconds): int;

    public function lPush(string $key, string ...$values): int
    {
        return (int) $this->command('LPUSH', array_merge([$key], $values));
    }

    public function rPush(string $key, string ...$values): int
    {
        return (int) $this->command('RPUSH', array_merge([$key], $values));
    }

    public function lPop(string $key): ?string
    {
        $reply = $this->command('LPOP', [$key]);

        return $reply === null ? null : (string) $reply;
    }

    public function rPop(string $key): ?string
    {
        $reply = $this->command('RPOP', [$key]);

        return $reply === null ? null : (string) $reply;
    }

    /**
     * @return list<string>
     */
    public function lRange(string $key, int $start, int $stop): array
    {
        /** @var array<int, mixed> $reply */
        $reply = $this->command('LRANGE', [$key, $start, $stop]);

        return array_map(static fn(mixed $item): string => (string) $item, array_values($reply));
    }

    public function lLen(string $key): int
    {
        return (int) $this->command('LLEN', [$key]);
    }

    public function lRem(string $key, string $value, int $count = 0): int
    {
        return (int) $this->command('LREM', [$key, $count, $value]);
    }

    public function lTrim(string $key, int $start, int $stop): bool
    {
        return $this->command('LTRIM', [$key, $start, $stop]) !== null;
    }

    /**
     * BLPOP: waits until one of the keys has an element, or the timeout runs out.
     *
     * Returns [key, value] — which key answered matters when several were watched — or
     * null when nothing arrived in time. `timeoutSeconds: 0` waits for ever, and the
     * deadline this passes for it is none, whatever the connection's own timeoutMs is.
     *
     * @param list<string> $keys
     *
     * @return array{0: string, 1: string}|null
     */
    public function blPop(array $keys, float $timeoutSeconds): ?array
    {
        return $this->blockingPop(name: 'BLPOP', keys: $keys, timeoutSeconds: $timeoutSeconds);
    }

    /**
     * BRPOP, the tail-side mirror of blPop.
     *
     * @param list<string> $keys
     *
     * @return array{0: string, 1: string}|null
     */
    public function brPop(array $keys, float $timeoutSeconds): ?array
    {
        return $this->blockingPop(name: 'BRPOP', keys: $keys, timeoutSeconds: $timeoutSeconds);
    }

    /**
     * @param list<string> $keys
     *
     * @return array{0: string, 1: string}|null
     */
    protected function blockingPop(string $name, array $keys, float $timeoutSeconds): ?array
    {
        $arguments = $keys;

        $arguments[] = $timeoutSeconds;

        /** @var list<mixed>|null $reply */
        $reply = $this->command(
            $name,
            $arguments,
            blocking: true,
            timeoutMs: $this->blockingDeadlineMs($timeoutSeconds),
        );

        if ($reply === null || count($reply) < 2) {
            return null;
        }

        return [(string) $reply[0], (string) $reply[1]];
    }
}
