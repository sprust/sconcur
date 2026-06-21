<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient\Payloads;

use SConcur\Features\SocketClient\Payloads\Base\BaseSocketClientPayload;
use SConcur\Features\SocketClient\SocketClientCommandEnum;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Close command: close an open connection.
 *
 * Go: payloads.CloseParams (ext/internal/features/socketclient/payloads/payloads.go).
 */
readonly class ClosePayload extends BaseSocketClientPayload
{
    public function __construct(
        protected string $connectionId,
    ) {
    }

    protected function getCommand(): SocketClientCommandEnum
    {
        return SocketClientCommandEnum::Close;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return new ClosePayloadParameters(
            connectionId: $this->connectionId,
        );
    }
}
