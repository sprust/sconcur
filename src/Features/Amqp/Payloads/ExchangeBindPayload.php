<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The exchange.bind command: route the messages of the source exchange into the
 * destination one.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ExchangeBindPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ExchangeBindPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::ExchangeBind;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
