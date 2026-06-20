<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * Starts the TCP listener bound to the given address (e.g. "0.0.0.0:9100") with the
 * server tuning (timeouts in milliseconds, sizes in bytes). Messages are framed with
 * a 4-byte big-endian length prefix.
 *
 * Go: payloads.ServePayload (ext/internal/features/socketserver/payloads/payloads.go).
 */
readonly class ServePayload implements PayloadInterface
{
    public function __construct(
        private string $address,
        private int $readTimeoutMs,
        private int $writeTimeoutMs,
        private int $maxMessageBytes,
        private int $maxConcurrency,
        private int $shutdownTimeoutMs,
        private bool $reusePort,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::SocketServe;
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function getData(): array
    {
        return [
            'ad'  => $this->address,
            'rt'  => $this->readTimeoutMs,
            'wt'  => $this->writeTimeoutMs,
            'mmb' => $this->maxMessageBytes,
            'mc'  => $this->maxConcurrency,
            'sht' => $this->shutdownTimeoutMs,
            'rp'  => $this->reusePort,
        ];
    }
}
