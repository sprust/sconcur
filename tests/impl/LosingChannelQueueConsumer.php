<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use Closure;
use ReflectionProperty;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use stdClass;
use WeakReference;

/**
 * A QueueConsumer whose first delivery loses the channel it arrived on while its handler
 * is still on the message — the handle let go of, and the acknowledgement that follows
 * with nowhere to go.
 *
 * It is the one failure neither side hears about on its own. The broker's channel is
 * fine, so it goes on counting the message against that consumer's prefetch; this side
 * has nothing left to answer on. Reproduced by hand because a race between two coroutines
 * cannot be asked to happen, and the symptom it produces is this and nothing else: the
 * weak reference a delivery settles through resolves to nothing.
 *
 * Only the first message is taken away, so the redelivery that follows can be handled and
 * the run can end.
 */
class LosingChannelQueueConsumer extends QueueConsumer
{
    protected bool $lost = false;

    protected function runHandler(Closure $handler, Delivery $delivery): void
    {
        parent::runHandler(
            handler: $handler,
            delivery: $delivery,
        );

        if ($this->lost) {
            return;
        }

        $this->lost = true;

        static::dropChannel($delivery);
    }

    /**
     * Points the delivery at a channel that is already gone. An object of any kind will
     * do: what settling reads is the weak reference, and what it finds there is null.
     */
    protected static function dropChannel(Delivery $delivery): void
    {
        $vanished = new stdClass();

        $property = new ReflectionProperty(Delivery::class, 'channel');

        $property->setValue($delivery, WeakReference::create($vanished));

        unset($vanished);
    }
}
