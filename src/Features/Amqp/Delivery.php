<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use Closure;
use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\ConcurrentDeliveryUseException;
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
 *
 * Two channels can be behind one delivery, and only one of them is ever handed out. The
 * message arrived on a consumer's channel, which is where its acknowledgement has to go and
 * which, under a prefetch above one, carries the messages of the handlers running beside
 * this one; that channel stays inside the runtime. What `channel()` answers is a channel
 * lent to this handler alone (PublishChannelPool), so publishing from a handler cannot
 * disturb — or be disturbed by — its neighbours. A delivery from `Channel::consume()` or
 * `Channel::get()` is lent nothing: there the channel already belongs to one coroutine, and
 * `channel()` answers that one.
 */
class Delivery
{
    /**
     * Whether this delivery hands out a channel of its own rather than the one it arrived
     * on. Settled at construction and never cleared, so that a delivery whose loan has ended
     * answers with nothing instead of falling back on the channel the runtime keeps.
     */
    protected bool $lending;

    /** The channel lent to this handler, once it has asked for one. */
    protected ?Channel $lentChannel = null;

    /** Guards the moment the loan is taken, which waits for the broker. */
    protected bool $leasing = false;

    /**
     * @param WeakReference<Channel>  $channel the channel the message arrived on. Weak, so a
     *                                         delivery an application kept does not hold its
     *                                         channel — and through it the connection — open
     * @param bool                    $settled whether the broker already considers this
     *                                         delivery answered. True for an auto-acknowledged
     *                                         one: it was settled as it left, and settling it
     *                                         again is what closes the channel
     * @param null|Closure(): Channel $lend    how this handler gets a channel of its own, for
     *                                         a delivery that arrived on a shared one. Called
     *                                         at most once, and only if the handler asks;
     *                                         null leaves `channel()` answering the channel
     *                                         the message arrived on
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
        protected ?Closure $lend = null,
    ) {
        $this->lending = $lend !== null;
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

    /** Acknowledges the delivery: the broker may forget the message. */
    public function ack(): void
    {
        $this->settleWith(fn(Channel $channel) => $channel->ack(deliveryTag: $this->deliveryTag));
    }

    /**
     * Refuses the delivery. Requeued by default, which is what a failure that may pass
     * wants; `requeue: false` dead-letters the message, or drops it where the queue names no
     * exchange.
     *
     * One delivery, never a run of them: AMQP's batch form is not offered here, see
     * Channel::ack().
     */
    public function nack(bool $requeue = true): void
    {
        $this->settleWith(fn(Channel $channel) => $channel->nack(
            deliveryTag: $this->deliveryTag,
            requeue: $requeue,
        ));
    }

    /**
     * Refuses exactly this delivery. `reject` is `nack` defaulting the other way: a rejected
     * message is not put back unless asked for.
     */
    public function reject(bool $requeue = false): void
    {
        $this->settleWith(fn(Channel $channel) => $channel->reject(
            deliveryTag: $this->deliveryTag,
            requeue: $requeue,
        ));
    }

    /**
     * A channel that belongs to the coroutine handling this delivery — what a handler
     * publishes and declares through, republishing this message included.
     *
     * Under a supervised consumer it is not the channel the message arrived on: that one
     * carries the neighbouring handlers' messages as well, and publisher confirms are
     * channel-wide, so two handlers waiting on it would read each other's answers. The
     * channel answered here is lent to this handler alone and goes back when the handler
     * ends, which is why it must not be stored past that. Elsewhere — `Channel::consume()`,
     * `Channel::get()` — the arriving channel already belongs to one coroutine and is the
     * one answered.
     *
     * Null once the channel is gone: the handler has ended, or the reference — weak, so a
     * delivery an application kept does not hold a channel and through it a connection open
     * — has been collected.
     */
    public function channel(): ?Channel
    {
        if (!$this->lending) {
            return $this->channel->get();
        }

        if ($this->lentChannel !== null) {
            return $this->lentChannel;
        }

        // The loan is over: the handler has ended and its channel has gone back. Answering
        // with the channel the message arrived on instead would hand out the one thing this
        // whole arrangement keeps away from handlers.
        if ($this->lend === null) {
            return null;
        }

        // Taking the loan waits for the broker, so a second coroutine can arrive here while
        // the first is still inside it. Both would be lent a channel and only one could ever
        // be given back; the guard turns that into a failure the caller can see.
        if ($this->leasing) {
            throw new ConcurrentDeliveryUseException(
                message: 'Could not lend a channel: this delivery is already taking one for another'
                    . ' coroutine. A delivery belongs to the handler it was given to — where work'
                    . ' fans out, give each coroutine a channel of its own from the connection.',
            );
        }

        $this->leasing = true;

        try {
            $this->lentChannel = ($this->lend)();
        } finally {
            $this->leasing = false;
        }

        return $this->lentChannel;
    }

    /**
     * Ends the loan: whatever was lent to this handler is going back to the pool, and this
     * delivery stops answering with it.
     *
     * @internal called by the supervised consumer when the handler returns. A delivery an
     *           application kept beyond its handler answers null from then on, exactly as it
     *           does for a channel that was closed.
     */
    public function releaseChannel(): ?Channel
    {
        $lent = $this->lentChannel;

        $this->lend        = null;
        $this->lentChannel = null;

        return $lent;
    }

    /**
     * @param callable(Channel): void $settle
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
