<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A delivery that could not be routed to a consumer of the channel it arrived on — the
 * consumer tag it carries belongs to no known AMQPQueue. The delivery itself is
 * reachable through getEnvelope(), exactly as in ext-amqp.
 *
 * The instance carrying the envelope is built by Support\OrphanedEnvelopeException: the
 * extension's class declares no constructor of its own, and the calque keeps that public
 * surface untouched.
 */
class AMQPEnvelopeException extends AMQPException
{
    protected AMQPEnvelope $envelope;

    public function getEnvelope(): AMQPEnvelope
    {
        return $this->envelope;
    }
}
