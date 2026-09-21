<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Consumer\QueueConsumer;

/**
 * A QueueConsumer that sweeps its idle channel handles in the one window where a sweep
 * could take the handle of a delivery about to be worked on: after channelFor() has
 * answered and before the delivery is marked in flight.
 *
 * In a running worker that window is entered by another coroutine — a delivery on a
 * second queue misses the registry and sweeps, and automatic preemption can put it
 * between two neighbouring statements. Here the sweep is made from inside the window
 * instead, because a race whose window is a few opcodes wide cannot be asked to happen.
 */
class SweepingQueueConsumer extends QueueConsumer
{
    protected function channelFor(Connection $connection, string $channelId): Channel
    {
        $channel = parent::channelFor(
            connection: $connection,
            channelId: $channelId,
        );

        $this->forgetIdleChannels();

        return $channel;
    }
}
