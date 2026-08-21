<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The basic.recover command: redeliver the messages the consumer has not acknowledged.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class RecoverPayload extends BaseAmqpPayload
{
    public function __construct(
        protected RecoverPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::Recover;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
