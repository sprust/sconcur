<?php

declare(strict_types=1);

namespace SConcur\Features\WsServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * Starts the WebSocket listener bound to the given address (e.g. "0.0.0.0:9200") with
 * the server tuning (timeouts in milliseconds, sizes in bytes). The listener is a
 * net/http.Server; a request carrying a valid WebSocket upgrade becomes a streamed
 * connection, every other request is answered 426 Upgrade Required.
 *
 * Go: payloads.ServePayload (ext/internal/features/wsserver/payloads/payloads.go).
 */
readonly class ServePayload implements PayloadInterface
{
    /**
     * @param list<string> $allowedOrigins host patterns accepted by the origin check (empty = allow any)
     * @param list<string> $subprotocols   negotiated WebSocket subprotocols (empty = none)
     */
    public function __construct(
        private string $address,
        private int $handshakeTimeoutMs,
        private int $idleTimeoutMs,
        private int $writeTimeoutMs,
        private int $pingIntervalMs,
        private int $maxMessageBytes,
        private int $maxConcurrency,
        private int $shutdownTimeoutMs,
        private bool $reusePort,
        private string $path,
        private array $allowedOrigins,
        private array $subprotocols,
        private string $telemetrySocket,
        private string $serverName,
        private int $telemetryIntervalMs,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::WsServe;
    }

    /**
     * @return array<string, int|string|bool|list<string>>
     */
    public function getData(): array
    {
        return [
            'ad'  => $this->address,
            'hst' => $this->handshakeTimeoutMs,
            'it'  => $this->idleTimeoutMs,
            'wt'  => $this->writeTimeoutMs,
            'pi'  => $this->pingIntervalMs,
            'mmb' => $this->maxMessageBytes,
            'mc'  => $this->maxConcurrency,
            'sht' => $this->shutdownTimeoutMs,
            'rp'  => $this->reusePort,
            'pt'  => $this->path,
            'ao'  => $this->allowedOrigins,
            'sp'  => $this->subprotocols,
            'ts'  => $this->telemetrySocket,
            'sn'  => $this->serverName,
            'ti'  => $this->telemetryIntervalMs,
        ];
    }
}
