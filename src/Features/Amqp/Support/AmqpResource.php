<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Features\Amqp\AMQPQueue;

/**
 * The internal base of the four calque classes that own a resource living on the Go side.
 * It carries exactly two things, and both are here rather than on the classes themselves
 * because PHP only lets one object read another's protected state through a common
 * declaring class:
 *
 * - the id of the Go-side resource (the connection handle for AMQPConnection, the channel
 *   for AMQPChannel), which AMQPChannel, AMQPQueue and AMQPExchange must read off the
 *   object they were constructed with;
 * - the channel's consumer registry, which AMQPQueue writes when it starts consuming and
 *   reads when a delivery has to be routed back to the queue that owns its consumer tag.
 *
 * Keeping them here is what lets the public surface of the calque stay byte-for-byte the
 * one ext-amqp exposes — no extra getters had to be invented for the feature's own use.
 */
abstract class AmqpResource
{
    /** The id of the resource on the Go side; empty while nothing is open. */
    protected string $internalId = '';

    /**
     * Consumers registered on this channel, by the consumer tag the broker assigned.
     * Only AMQPChannel fills it.
     *
     * @var array<string, AMQPQueue>
     */
    protected array $internalConsumers = [];

    /**
     * ext-amqp keeps its timeouts in (fractional) seconds; the wire carries milliseconds,
     * as everywhere else in the project.
     */
    protected static function toMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }
}
