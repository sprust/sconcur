<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient\Payloads;

use SConcur\Features\SocketClient\Payloads\Base\BaseSocketClientPayload;
use SConcur\Features\SocketClient\SocketClientCommandEnum;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Connect command: dial the remote address and open a streaming connection (the
 * first result is the connection metadata, subsequent results are inbound frames).
 *
 * Go: payloads.ConnectParams (ext/internal/features/socketclient/payloads/payloads.go).
 */
readonly class ConnectPayload extends BaseSocketClientPayload
{
    public function __construct(
        protected ConnectPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): SocketClientCommandEnum
    {
        return SocketClientCommandEnum::Connect;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
