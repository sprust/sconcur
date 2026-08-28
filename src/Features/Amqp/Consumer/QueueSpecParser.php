<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

use JsonException;
use SConcur\Exceptions\Amqp\InvalidQueueSpecException;
use SConcur\Features\Amqp\ConnectionOptions;

/**
 * Reads the queue list a consumer worker was launched with — one argv flag holding JSON,
 * because a worker is configured through argv and the master JSON-encodes non-scalars:
 *
 *     --queues=[{"name":"orders","coroutineCount":8},{"name":"emails"}]
 *
 * A list of objects rather than a delimited string, because AMQP allows almost any UTF-8 in
 * a queue name — colons included, and "tenant:1:orders" is ordinary — so any separator
 * inside a name would make the parse ambiguous.
 *
 * Everything is validated before the first basic.consume: a typo in a config must fail at
 * startup with a sentence, not as a broker error minutes into a run.
 */
readonly class QueueSpecParser
{
    /**
     * @return list<QueueSpec>
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

            $spec = static::specFromEntry(
                entry: $entry,
                index: $index,
            );

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
     * The channels this list costs on the delivery connection — one per consumer, which is
     * one per unit of a queue's weight.
     *
     * The channels handlers publish on are not counted here: they are lent from a pool that
     * opens connections of its own (PublishChannelPool), precisely so that a prefetch worth
     * raising cannot run this budget out.
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
     * A consumer is a channel, and a connection runs out of channel numbers at
     * ConnectionOptions::MAX_CHANNELS. Diagnosed here rather than as the broker's "504 channel
     * id space exhausted" on whichever consumer happened to start last.
     *
     * @param list<QueueSpec> $specs
     */
    protected static function assertChannelBudget(array $specs): void
    {
        $total    = static::channelCount($specs);
        $capacity = ConnectionOptions::usableChannels();

        if ($total <= $capacity) {
            return;
        }

        throw new InvalidQueueSpecException(
            message: sprintf(
                'queues: %d consumers need %d channels, but one connection carries %d.'
                . ' Split the queues over more workers, or give a group its own connection_name.',
                $total,
                $total,
                $capacity,
            ),
        );
    }
}
