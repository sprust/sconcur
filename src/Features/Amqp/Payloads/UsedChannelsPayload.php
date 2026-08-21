<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The UsedChannels command: how many channels the connection handle currently holds
 * open, counted in the Go-side registry.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class UsedChannelsPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ConnectionPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::UsedChannels;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
