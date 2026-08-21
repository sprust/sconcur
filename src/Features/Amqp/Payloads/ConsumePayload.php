<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The basic.consume command — the streaming one: the first result carries the consumer
 * tag the broker assigned, every following one carries a delivery.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ConsumePayload extends BaseAmqpPayload
{
    public function __construct(
        protected ConsumePayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::Consume;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
