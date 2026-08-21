<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPEnvelopeException;

/**
 * The AMQPEnvelopeException a consume loop raises when a delivery carries a consumer tag
 * no AMQPQueue of the channel claims — ext-amqp's "Orphaned envelope".
 *
 * It exists as a subclass so the delivery can be attached without giving
 * AMQPEnvelopeException a constructor the extension does not have; `catch
 * (AMQPEnvelopeException $exception)` and its getEnvelope() work exactly as before.
 */
class OrphanedEnvelopeException extends AMQPEnvelopeException
{
    public function __construct(string $message, AMQPEnvelope $envelope)
    {
        parent::__construct(message: $message);

        $this->envelope = $envelope;
    }
}
