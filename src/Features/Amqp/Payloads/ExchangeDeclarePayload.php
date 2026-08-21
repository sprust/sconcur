<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The exchange.declare command (declare-passive when the passive parameter is set).
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ExchangeDeclarePayload extends BaseAmqpPayload
{
    public function __construct(
        protected ExchangeDeclarePayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::ExchangeDeclare;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
