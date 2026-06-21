<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Send command: the connection to write to and the frame bytes
 * (binary-safe).
 *
 * Go: payloads.SendParams (ext/internal/features/socketclient/payloads/payloads.go).
 */
readonly class SendPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $connectionId,
        protected string $data,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getData(): array
    {
        return [
            'cid' => $this->connectionId,
            'dt'  => $this->data,
        ];
    }
}
