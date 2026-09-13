<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

/** Hashes. */
trait HashCommandsTrait
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

    public function hGet(string $key, string $field): ?string
    {
        $reply = $this->command('HGET', [$key, $field]);

        return $reply === null ? null : (string) $reply;
    }

    /**
     * HSET with one field or many. Returns how many fields were new.
     *
     * The key type is int|string for the reason pairsToMap gives: a caller cannot
     * write an integer-like field as a string key either. The cast below takes it
     * back to the bytes the server stores.
     *
     * @param array<array-key, string|int|float> $fields
     */
    public function hSet(string $key, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $arguments = [$key];

        foreach ($fields as $field => $value) {
            $arguments[] = (string) $field;
            $arguments[] = $value;
        }

        return (int) $this->command('HSET', $arguments);
    }

    /**
     * HMGET keyed by field, like mGet: a missing field is null, and a field asked
     * for twice appears once.
     *
     * A field that looks like an integer comes back as an int key, for the reason
     * mGet gives.
     *
     * @param array<array-key, string> $fields
     *
     * @return array<int|string, string|null>
     */
    public function hMGet(string $key, array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        // Dense, so the positions line up with the reply — see mGet.
        $fields = array_values($fields);

        /** @var list<string|null> $reply */
        $reply = $this->command('HMGET', array_merge([$key], $fields));

        $values = [];

        foreach ($fields as $position => $field) {
            $value          = $reply[$position] ?? null;
            $values[$field] = $value === null ? null : (string) $value;
        }

        return $values;
    }

    /**
     * The whole hash as field => value.
     *
     * The shape is built here: the server answers with field and value alternating
     * in one flat list, and folding it is the point of the method existing.
     *
     * A field that looks like an integer comes back as an int key — see pairsToMap.
     *
     * @return array<int|string, string>
     */
    public function hGetAll(string $key): array
    {
        /** @var array<int|string, string> $reply */
        $reply = $this->command('HGETALL', [$key]);

        return static::pairsToMap($reply);
    }

    public function hDel(string $key, string ...$fields): int
    {
        return $fields === []
            ? 0
            : (int) $this->command('HDEL', array_merge([$key], $fields));
    }

    public function hExists(string $key, string $field): bool
    {
        return (int) $this->command('HEXISTS', [$key, $field]) === 1;
    }

    public function hIncrBy(string $key, string $field, int $by = 1): int
    {
        return (int) $this->command('HINCRBY', [$key, $field, $by]);
    }

    public function hLen(string $key): int
    {
        return (int) $this->command('HLEN', [$key]);
    }

    /**
     * @return list<string>
     */
    public function hKeys(string $key): array
    {
        /** @var array<int, mixed> $reply */
        $reply = $this->command('HKEYS', [$key]);

        return array_map(static fn(mixed $item): string => (string) $item, array_values($reply));
    }

    /**
     * @return list<string>
     */
    public function hVals(string $key): array
    {
        /** @var array<int, mixed> $reply */
        $reply = $this->command('HVALS', [$key]);

        return array_map(static fn(mixed $item): string => (string) $item, array_values($reply));
    }

    /**
     * A flat field/value list into a map.
     *
     * The list is what the server sends, always: RESP3 would answer with a map
     * instead, and the dsn refuses RESP3 for exactly this reason — the shape has
     * to be known here, and guessing it from the decoded array was wrong in both
     * directions (a hash whose fields are "0" and "1" decodes into something a
     * list check calls a list).
     *
     * The key type is PHP's, not a choice made here: a canonical integer string
     * becomes an int key on assignment, and no array can hold "1" as a string. So a
     * hash whose fields are "0" and "1" folds into something array_is_list() calls a
     * list and json_encode writes as an array. The map says int|string rather than
     * promising a string key it cannot keep; cast a key back with (string) before
     * passing it to a method that takes one.
     *
     * @param array<int|string, mixed> $reply
     *
     * @return array<int|string, string>
     */
    protected static function pairsToMap(array $reply): array
    {
        $map    = [];
        $values = array_values($reply);
        $count  = count($values);

        for ($index = 0; $index + 1 < $count; $index += 2) {
            $map[(string) $values[$index]] = (string) $values[$index + 1];
        }

        return $map;
    }
}
