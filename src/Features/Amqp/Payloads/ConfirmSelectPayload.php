<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The confirm.select command: put the channel into publisher-confirm mode.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ConfirmSelectPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ConfirmSelectPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::ConfirmSelect;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
