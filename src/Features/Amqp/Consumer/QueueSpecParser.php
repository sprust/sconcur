<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

use JsonException;
use SConcur\Exceptions\Amqp\InvalidQueueSpecException;
use const SConcur\Features\Amqp\PHP_AMQP_MAX_CHANNELS;

/**
 * Reads the queue list a consumer worker was launched with. It travels as one argv
 * flag holding JSON, because a worker is configured through argv and the master
 * JSON-encodes whatever is not a scalar:
 *
 *     --queues=[{"name":"orders","coroutineCount":8},{"name":"emails"}]
 *
 * A list of objects rather than a delimited string on purpose: AMQP allows almost any
 * UTF-8 in a queue name, colons included, and names like "tenant:1:orders" are
 * ordinary — any separator inside a name would make the parse ambiguous. Objects also
 * take a new field without inventing a new separator for it.
 *
 * Everything is validated here, before the first basic.consume: a typo in a config
 * must fail at startup with a sentence, not as a broker error minutes into a run.
 */
readonly class QueueSpecParser
{
    /**
     * How many channels one connection is left to open. The protocol ceiling is
     * PHP_AMQP_MAX_CHANNELS and channel numbering starts at one, so the last usable
     * number is one below it.
     */
    protected const int MAX_CHANNELS_PER_CONNECTION = PHP_AMQP_MAX_CHANNELS - 1;

    /**
     * @return list<QueueSpec>
     *
     * @throws InvalidQueueSpecException the JSON is malformed, an entry is not usable,
     *                                   or the total exceeds one connection's channels
     */
    public static function parse(string $json): array
    {
        $decoded = static::decode($json);

        $specs = [];
        $seen  = [];

        foreach ($decoded as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidQueueSpecException(
                    message: "queues[$index]: every entry must be an object",
                );
            }

            $spec = static::specFromEntry(entry: $entry, index: $index);

            if (isset($seen[$spec->name])) {
                throw new InvalidQueueSpecException(
                    message: "queues[$index]: queue \"{$spec->name}\" is listed twice",
                );
            }

            $seen[$spec->name] = true;

            $specs[] = $spec;
        }

        if ($specs === []) {
            throw new InvalidQueueSpecException(
                message: 'queues: at least one queue is required',
            );
        }

        static::assertChannelBudget($specs);

        return $specs;
    }

    /**
     * The channels this list costs on one connection — one per coroutine, since a
     * channel is never shared between coroutines.
     *
     * @param list<QueueSpec> $specs
     */
    public static function channelCount(array $specs): int
    {
        $total = 0;

        foreach ($specs as $spec) {
            $total += $spec->coroutineCount;
        }

        return $total;
    }

    /**
     * @return array<mixed>
     *
     * @throws InvalidQueueSpecException
     */
    protected static function decode(string $json): array
    {
        if (trim($json) === '') {
            throw new InvalidQueueSpecException(
                message: 'queues: no queue list was given',
            );
        }

        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidQueueSpecException(
                message: 'queues: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new InvalidQueueSpecException(
                message: 'queues: expected a JSON list of objects',
            );
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $entry
     *
     * @throws InvalidQueueSpecException
     */
    protected static function specFromEntry(array $entry, int $index): QueueSpec
    {
        $unknown = array_diff(array_keys($entry), ['name', 'coroutineCount', 'prefetchCount']);

        if ($unknown !== []) {
            throw new InvalidQueueSpecException(
                message: "queues[$index]: unknown key(s) " . implode(', ', array_map(strval(...), $unknown)),
            );
        }

        $name = isset($entry['name']) ? (string) $entry['name'] : '';

        if ($name === '') {
            throw new InvalidQueueSpecException(
                message: "queues[$index]: \"name\" is required",
            );
        }

        $coroutineCount = isset($entry['coroutineCount']) ? (int) $entry['coroutineCount'] : 1;

        if ($coroutineCount < 1) {
            throw new InvalidQueueSpecException(
                message: "queues[$index]: \"coroutineCount\" must be at least 1 for \"$name\"",
            );
        }

        $prefetchCount = isset($entry['prefetchCount']) ? (int) $entry['prefetchCount'] : null;

        if ($prefetchCount !== null && $prefetchCount < 0) {
            throw new InvalidQueueSpecException(
                message: "queues[$index]: \"prefetchCount\" cannot be negative for \"$name\"",
            );
        }

        return new QueueSpec(
            name: $name,
            coroutineCount: $coroutineCount,
            prefetchCount: $prefetchCount,
        );
    }

    /**
     * A coroutine is a channel, and a connection runs out of channel numbers at
     * PHP_AMQP_MAX_CHANNELS. Diagnosed here rather than as the broker's "504 channel
     * id space exhausted" on whichever coroutine happened to start last.
     *
     * @param list<QueueSpec> $specs
     *
     * @throws InvalidQueueSpecException
     */
    protected static function assertChannelBudget(array $specs): void
    {
        $total = static::channelCount($specs);

        if ($total <= self::MAX_CHANNELS_PER_CONNECTION) {
            return;
        }

        throw new InvalidQueueSpecException(
            message: sprintf(
                'queues: %d coroutines need %d channels, but one connection carries %d.'
                . ' Split the queues over more workers, or give a group its own connection_name.',
                $total,
                $total,
                self::MAX_CHANNELS_PER_CONNECTION,
            ),
        );
    }
}
