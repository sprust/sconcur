<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient\Payloads;

use SConcur\Features\WsClient\Payloads\Base\BaseWsClientPayload;
use SConcur\Features\WsClient\WsClientCommandEnum;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Connect command: dial the remote ws:// URL and open a streaming connection (the
 * first result is the connection metadata, subsequent results are inbound messages).
 *
 * Go: payloads.ConnectParams (ext/internal/features/wsclient/payloads/payloads.go).
 */
readonly class ConnectPayload extends BaseWsClientPayload
{
    public function __construct(
        protected ConnectPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): WsClientCommandEnum
    {
        return WsClientCommandEnum::Connect;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
