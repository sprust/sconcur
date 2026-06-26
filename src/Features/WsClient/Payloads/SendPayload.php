<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient\Payloads;

use SConcur\Features\WsClient\Payloads\Base\BaseWsClientPayload;
use SConcur\Features\WsClient\WsClientCommandEnum;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Send command: push one message to the peer of an open connection. Text by
 * default; pass $binary = true for a binary message.
 *
 * Go: payloads.SendParams (ext/internal/features/wsclient/payloads/payloads.go).
 */
readonly class SendPayload extends BaseWsClientPayload
{
    /** A UTF-8 text message. */
    private const int TYPE_TEXT = 0;

    /** A binary message (carries any bytes). */
    private const int TYPE_BINARY = 1;

    public function __construct(
        protected string $connectionId,
        protected string $data,
        protected bool $binary = false,
    ) {
    }

    protected function getCommand(): WsClientCommandEnum
    {
        return WsClientCommandEnum::Send;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return new SendPayloadParameters(
            connectionId: $this->connectionId,
            messageType: $this->binary ? self::TYPE_BINARY : self::TYPE_TEXT,
            data: $this->data,
        );
    }
}
