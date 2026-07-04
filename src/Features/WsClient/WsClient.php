<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient;

use SConcur\Exceptions\WsClient\WsClientConnectException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\WsClient\Dto\Connection;
use SConcur\Features\WsClient\Payloads\ConnectPayload;
use SConcur\Features\WsClient\Payloads\ConnectPayloadParameters;
use SConcur\Transport\MessagePackTransport;
use Throwable;

/**
 * Asynchronous WebSocket client — the dial-side mirror of WsServer. The whole network
 * round-trip (dial, upgrade handshake, read, write) lives in the Go extension; connect()
 * runs in a goroutine while the calling coroutine suspends, so many connections fan out
 * concurrently. Outside a WaitGroup the same call works synchronously.
 *
 * connect() returns a long-lived Connection the caller drives itself: read() pulls
 * inbound messages, write() pushes messages (text or binary), close() ends it. See
 * docs/websocket-client.md.
 */
readonly class WsClient
{
    public function __construct(
        protected WsClientOptions $options = new WsClientOptions(),
    ) {
    }

    /**
     * Dials the remote ws:// URL (e.g. "ws://127.0.0.1:9200/") and returns an open
     * Connection. Throws WsClientConnectException on a dial/handshake failure (connection
     * refused, DNS failure, connect timeout, rejected upgrade).
     */
    public function connect(string $url): Connection
    {
        try {
            $result = FeatureExecutor::exec(
                payload: new ConnectPayload(
                    new ConnectPayloadParameters(
                        address: $url,
                        connectTimeoutMs: $this->options->connectTimeoutMs,
                        readTimeoutMs: $this->options->readTimeoutMs,
                        writeTimeoutMs: $this->options->writeTimeoutMs,
                        maxMessageBytes: $this->options->maxMessageBytes,
                        subprotocols: $this->options->subprotocols,
                    ),
                ),
            );
        } catch (Throwable $exception) {
            throw new WsClientConnectException(
                message: "Failed to connect to $url: " . $exception->getMessage(),
                previous: $exception,
            );
        }

        /** @var array<string, mixed> $meta */
        $meta = MessagePackTransport::unpack($result->payload);

        return new Connection(
            id: (string) ($meta['cid'] ?? ''),
            remoteAddr: (string) ($meta['ra'] ?? ''),
            localAddr: (string) ($meta['la'] ?? ''),
            inboundKey: $result->key,
            subprotocol: (string) ($meta['su'] ?? ''),
        );
    }
}
