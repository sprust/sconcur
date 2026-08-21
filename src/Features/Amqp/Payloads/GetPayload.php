<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The basic.get command: the next message of the queue, or an empty result when the
 * queue is empty.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class GetPayload extends BaseAmqpPayload
{
    public function __construct(
        protected GetPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::Get;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
