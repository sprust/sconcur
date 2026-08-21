<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Connect command: open a connection to the broker (or take one from the Go-side
 * pool) and return its handle id together with the values negotiated in the handshake.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ConnectPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ConnectPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::Connect;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
