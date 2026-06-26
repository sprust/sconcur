<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Send command: the connection to write to, the WebSocket message type
 * (0 text, 1 binary) and the message bytes (binary-safe).
 *
 * Go: payloads.SendParams (ext/internal/features/wsclient/payloads/payloads.go).
 */
readonly class SendPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $connectionId,
        protected int $messageType,
        protected string $data,
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function getData(): array
    {
        return [
            'cid' => $this->connectionId,
            'mt'  => $this->messageType,
            'dt'  => $this->data,
        ];
    }
}
