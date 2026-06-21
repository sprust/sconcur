<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Connect command: the remote address plus the per-connection tuning.
 * Carries the mandatory execution bounds for a long-lived connection (connect / read /
 * write timeouts), applied on the Go side as dial and per-frame deadlines.
 *
 * Go: payloads.ConnectParams (ext/internal/features/socketclient/payloads/payloads.go).
 */
readonly class ConnectPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $address,
        protected int $connectTimeoutMs,
        protected int $readTimeoutMs,
        protected int $writeTimeoutMs,
        protected int $maxMessageBytes,
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function getData(): array
    {
        return [
            'ad'  => $this->address,
            'ct'  => $this->connectTimeoutMs,
            'rt'  => $this->readTimeoutMs,
            'wt'  => $this->writeTimeoutMs,
            'mmb' => $this->maxMessageBytes,
        ];
    }
}
