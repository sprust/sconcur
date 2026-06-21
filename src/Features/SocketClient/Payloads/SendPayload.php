<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient\Payloads;

use SConcur\Features\SocketClient\Payloads\Base\BaseSocketClientPayload;
use SConcur\Features\SocketClient\SocketClientCommandEnum;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Send command: push one length-prefixed frame to the peer of an open connection.
 *
 * Go: payloads.SendParams (ext/internal/features/socketclient/payloads/payloads.go).
 */
readonly class SendPayload extends BaseSocketClientPayload
{
    public function __construct(
        protected string $connectionId,
        protected string $data,
    ) {
    }

    protected function getCommand(): SocketClientCommandEnum
    {
        return SocketClientCommandEnum::Send;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return new SendPayloadParameters(
            connectionId: $this->connectionId,
            data: $this->data,
        );
    }
}
