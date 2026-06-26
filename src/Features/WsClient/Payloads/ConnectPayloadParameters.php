<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Connect command: the remote ws:// URL plus the per-connection tuning.
 * Carries the mandatory execution bounds for a long-lived connection (connect / read /
 * write timeouts), applied on the Go side as the dial/handshake and per-message
 * deadlines.
 *
 * Go: payloads.ConnectParams (ext/internal/features/wsclient/payloads/payloads.go).
 */
readonly class ConnectPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param list<string> $subprotocols
     */
    public function __construct(
        protected string $address,
        protected int $connectTimeoutMs,
        protected int $readTimeoutMs,
        protected int $writeTimeoutMs,
        protected int $maxMessageBytes,
        protected array $subprotocols,
    ) {
    }

    /**
     * @return array<string, int|string|list<string>>
     */
    public function getData(): array
    {
        return [
            'ad'  => $this->address,
            'ct'  => $this->connectTimeoutMs,
            'rt'  => $this->readTimeoutMs,
            'wt'  => $this->writeTimeoutMs,
            'mmb' => $this->maxMessageBytes,
            'sp'  => $this->subprotocols,
        ];
    }
}
