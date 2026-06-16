<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient;

/**
 * Per-request tuning for the HTTP client. All timeouts are in milliseconds; the
 * PHP defaults mirror the Go-side defaults. A single instance is shared by every
 * request a HttpClient sends.
 *
 * requestTimeoutMs is the mandatory hard limit for the whole operation (connect +
 * send + reading the entire body), enforced on the Go side as a context deadline.
 */
readonly class HttpClientOptions
{
    /**
     * @param int  $requestTimeoutMs        full request deadline (connect + send + read whole body);
     *                                      0 disables it (not recommended)
     * @param int  $connectTimeoutMs        TCP/TLS connection establishment limit
     * @param int  $responseHeaderTimeoutMs limit waiting for the status line + headers
     * @param int  $maxResponseBody         response body cap in bytes; 0 means unlimited (watch for OOM)
     * @param bool $followRedirects         follow 3xx redirects
     * @param int  $maxRedirects            max redirect hops when $followRedirects is true
     * @param int  $chunkSize               response-body read granularity (inline first chunk + each streamed chunk)
     * @param bool $verifyTls               verify TLS certificates (set false only for self-signed in dev)
     * @param int  $maxIdleConns            total idle keep-alive connections kept in the pool
     * @param int  $maxIdleConnsPerHost     idle keep-alive connections kept per host
     * @param int  $idleConnTimeoutMs       how long an idle keep-alive connection is kept before closing
     * @param int  $tlsHandshakeTimeoutMs   TLS handshake limit
     * @param bool $streamRequestBody       stream the request body to Go in chunks (chunkSize granularity) instead of
     *                                      buffering it whole; gives write-backpressure for large uploads. Off by
     *                                      default (v1 buffered behaviour).
     * @param bool $throwOnToStringError    whether ResponseBodyStream::__toString may throw on a read error. PSR-7 says
     *                                      __toString must not throw, so when false a read failure is turned into an
     *                                      E_USER_WARNING and an empty string. Defaults to true, mirroring Guzzle's
     *                                      stream behaviour on PHP >= 7.4 (re-throw).
     */
    public function __construct(
        public int $requestTimeoutMs = 30_000,
        public int $connectTimeoutMs = 10_000,
        public int $responseHeaderTimeoutMs = 15_000,
        public int $maxResponseBody = 0,
        public bool $followRedirects = true,
        public int $maxRedirects = 10,
        public int $chunkSize = 65_536,
        public bool $verifyTls = true,
        public int $maxIdleConns = 100,
        public int $maxIdleConnsPerHost = 16,
        public int $idleConnTimeoutMs = 90_000,
        public int $tlsHandshakeTimeoutMs = 10_000,
        public bool $streamRequestBody = false,
        public bool $throwOnToStringError = true,
    ) {
    }
}
