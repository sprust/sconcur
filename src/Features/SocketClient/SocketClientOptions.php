<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient;

/**
 * Per-connection tuning for the socket client. All timeouts are in milliseconds; the
 * PHP defaults mirror the Go-side defaults. A single instance is shared by every
 * connection a SocketClient dials.
 *
 * A long-lived connection has no single "operation time": connectTimeoutMs bounds the
 * dial, readTimeoutMs the idle wait for an inbound frame, writeTimeoutMs one frame
 * write — these are the mandatory execution bounds (mirroring SocketServer, which has
 * no per-message timeout either).
 */
readonly class SocketClientOptions
{
    /**
     * @param int $connectTimeoutMs TCP connection establishment limit
     * @param int $readTimeoutMs    idle timeout while waiting for the next inbound frame in read()
     *                              (0 = disabled; a connection may stay idle forever)
     * @param int $writeTimeoutMs   max time to write one frame to the peer before it fails
     * @param int $maxMessageBytes  max length of a single inbound frame (guards against a huge
     *                              length prefix); an oversize frame ends the connection's input
     */
    public function __construct(
        public int $connectTimeoutMs = 10_000,
        public int $readTimeoutMs = 0,
        public int $writeTimeoutMs = 30_000,
        public int $maxMessageBytes = 1_048_576,
    ) {
    }
}
