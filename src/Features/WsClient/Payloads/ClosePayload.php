<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient\Payloads;

use SConcur\Features\WsClient\Payloads\Base\BaseWsClientPayload;
use SConcur\Features\WsClient\WsClientCommandEnum;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Close command: close an open connection.
 *
 * Rust: payloads::CloseParams (ext/src/features/wsclient/payloads.rs).
 */
readonly class ClosePayload extends BaseWsClientPayload
{
    public function __construct(
        protected string $connectionId,
    ) {
    }

    protected function getCommand(): WsClientCommandEnum
    {
        return WsClientCommandEnum::Close;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return new ClosePayloadParameters(
            connectionId: $this->connectionId,
        );
    }
}
