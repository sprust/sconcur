<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use SConcur\Features\Amqp\Consumer\QueueConsumer;

/**
 * A QueueConsumer that will say how many channel handles it is holding.
 *
 * The count is what tells a handle the runtime still needs from one left behind by a
 * consumer the broker took away, and there is no other way to see it: a handler never
 * meets these channels, by design.
 */
class InspectableQueueConsumer extends QueueConsumer
{
    /** How many channel handles the run is holding right now. */
    public function heldChannels(): int
    {
        return count($this->channels);
    }
}
