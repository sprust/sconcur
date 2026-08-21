<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The ChannelOpen command: open a channel on the connection and return its handle id and
 * its AMQP channel number.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ChannelOpenPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ChannelOpenPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::ChannelOpen;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
