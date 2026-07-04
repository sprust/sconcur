<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient;

use SConcur\Exceptions\SocketClient\SocketClientConnectException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\SocketClient\Dto\Connection;
use SConcur\Features\SocketClient\Payloads\ConnectPayload;
use SConcur\Features\SocketClient\Payloads\ConnectPayloadParameters;
use SConcur\Transport\MessagePackTransport;
use Throwable;

/**
 * Asynchronous TCP client with length-prefix framing — the dial-side mirror of
 * SocketServer. The whole network round-trip (DNS, dial, read, write) lives in the Go
 * extension; connect() runs in a goroutine while the calling coroutine suspends, so
 * dozens of connections fan out concurrently. Outside a WaitGroup the same call works
 * synchronously.
 *
 * connect() returns a long-lived Connection the caller drives itself: read() pulls
 * inbound frames, write() pushes frames, close() ends it. See docs/socket-client.md.
 */
readonly class SocketClient
{
    public function __construct(
        protected SocketClientOptions $options = new SocketClientOptions(),
    ) {
    }

    /**
     * Dials the remote "host:port" and returns an open Connection. Throws
     * SocketClientConnectException on a dial failure (connection refused, DNS failure,
     * connect timeout).
     */
    public function connect(string $address): Connection
    {
        try {
            $result = FeatureExecutor::exec(
                payload: new ConnectPayload(
                    new ConnectPayloadParameters(
                        address: $address,
                        connectTimeoutMs: $this->options->connectTimeoutMs,
                        readTimeoutMs: $this->options->readTimeoutMs,
                        writeTimeoutMs: $this->options->writeTimeoutMs,
                        maxMessageBytes: $this->options->maxMessageBytes,
                    ),
                ),
            );
        } catch (Throwable $exception) {
            throw new SocketClientConnectException(
                message: "Failed to connect to $address: " . $exception->getMessage(),
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
        );
    }
}
