<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The exchange.unbind command: remove a binding between two exchanges.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ExchangeUnbindPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ExchangeBindPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::ExchangeUnbind;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
