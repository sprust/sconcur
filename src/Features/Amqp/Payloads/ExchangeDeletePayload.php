<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The exchange.delete command.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ExchangeDeletePayload extends BaseAmqpPayload
{
    public function __construct(
        protected ExchangeDeletePayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::ExchangeDelete;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
