<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\ChannelException;
use WeakReference;

/**
 * A message the broker delivered, and the means to settle it.
 *
 * Settling lives here rather than on the queue: an acknowledgement belongs to the channel
 * the message arrived on and names it by delivery tag, so a queue-side ack made the caller
 * carry a number between two objects and gave them the chance to carry the wrong one.
 *
 * A second settle is refused here rather than sent — the broker answers one by killing the
 * channel, taking every other consumer on it down as collateral.
 */
class Delivery
{
    /**
     * @param WeakReference<Channel> $channel the channel the message arrived on. Weak, so a
     *                                        delivery an application kept does not hold its
     *                                        channel — and through it the connection — open
     * @param bool                   $settled whether the broker already considers this
     *                                        delivery answered. True for an auto-acknowledged
     *                                        one: it was settled as it left, and settling it
     *                                        again is what closes the channel
     */
    public function __construct(
        public readonly string $body,
        public readonly string $routingKey,
        public readonly string $exchange,
        public readonly string $consumerTag,
        public readonly int $deliveryTag,
        public readonly bool $redelivered,
        public readonly MessageProperties $properties,
        protected WeakReference $channel,
        protected bool $settled = false,
    ) {
    }

    /** A header of the message, or null when it carries none by that name. */
    public function header(string $name): mixed
    {
        return $this->properties->header($name);
    }

    public function hasHeader(string $name): bool
    {
        return $this->properties->hasHeader($name);
    }

    /** Whether this delivery has already been acknowledged, refused or rejected. */
    public function isSettled(): bool
    {
        return $this->settled;
    }

    /**
     * Acknowledges the delivery: the broker may forget the message.
     *
     * @param bool $multiple acknowledge every delivery of this channel up to and including
     *                       this tag — the cheap way to settle a batch
     *
     * @throws ChannelException if the channel is gone or the delivery was already settled
     */
    public function ack(bool $multiple = false): void
    {
        $this->settleWith(fn(Channel $channel) => $channel->ack(
            deliveryTag: $this->deliveryTag,
            multiple: $multiple,
        ));
    }

    /**
     * Refuses the delivery. Requeued by default, which is what a failure that may pass
     * wants; `requeue: false` dead-letters the message, or drops it where the queue names no
     * exchange.
     *
     * @throws ChannelException if the channel is gone or the delivery was already settled
     */
    public function nack(bool $requeue = true, bool $multiple = false): void
    {
        $this->settleWith(fn(Channel $channel) => $channel->nack(
            deliveryTag: $this->deliveryTag,
            requeue: $requeue,
            multiple: $multiple,
        ));
    }

    /**
     * Refuses exactly this delivery. `reject` is `nack` without the batch form, defaulting
     * the other way: a rejected message is not put back unless asked for.
     *
     * @throws ChannelException if the channel is gone or the delivery was already settled
     */
    public function reject(bool $requeue = false): void
    {
        $this->settleWith(fn(Channel $channel) => $channel->reject(
            deliveryTag: $this->deliveryTag,
            requeue: $requeue,
        ));
    }

    /**
     * @param callable(Channel): void $settle
     *
     * @throws ChannelException if the channel is gone or the delivery was already settled
     */
    protected function settleWith(callable $settle): void
    {
        if ($this->settled) {
            throw new ChannelException(
                message: "Delivery $this->deliveryTag has already been settled; settling it twice"
                    . ' would make the broker close the channel. An auto-acknowledged delivery'
                    . ' arrives settled: the broker answered for it as it left.',
            );
        }

        $channel = $this->channel->get();

        if ($channel === null) {
            throw new ChannelException(
                message: "Could not settle delivery $this->deliveryTag. The channel it arrived on is gone.",
            );
        }

        // Marked before the command, not after: a settle that failed still reached the
        // broker in every case but a dead channel, and a retry of it is the double settle
        // this guard exists to prevent.
        $this->settled = true;

        $settle($channel);
    }
}
