<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The basic.ack command.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class AckPayload extends BaseAmqpPayload
{
    public function __construct(
        protected AckPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::Ack;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
