<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The ReturnWait command: wait for the messages the broker returned as unroutable and
 * return what arrived.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ReturnWaitPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ChannelPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::ReturnWait;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
