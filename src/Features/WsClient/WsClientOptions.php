<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient;

/**
 * Per-connection tuning for the ws client. All timeouts are in milliseconds; the PHP
 * defaults mirror the Go-side defaults. A single instance is shared by every connection
 * a WsClient dials.
 *
 * A long-lived connection has no single "operation time": connectTimeoutMs bounds the
 * dial and handshake, readTimeoutMs the idle wait for an inbound message, writeTimeoutMs
 * one message write — these are the mandatory execution bounds (mirroring WsServer, which
 * has no per-message timeout either).
 */
readonly class WsClientOptions
{
    /**
     * @param int          $connectTimeoutMs connection establishment limit (dial + handshake)
     * @param int          $readTimeoutMs    idle timeout while waiting for the next inbound message in read()
     *                                       (0 = disabled; a connection may stay idle forever)
     * @param int          $writeTimeoutMs   max time to write one message to the peer before it fails
     * @param int          $maxMessageBytes  max size of a single inbound message; an oversize message
     *                                       closes the connection with 1009 (0 = no limit)
     * @param list<string> $subprotocols     WebSocket subprotocols offered in the handshake (empty = none)
     */
    public function __construct(
        public int $connectTimeoutMs = 10_000,
        public int $readTimeoutMs = 0,
        public int $writeTimeoutMs = 30_000,
        public int $maxMessageBytes = 1_048_576,
        public array $subprotocols = [],
    ) {
    }
}
