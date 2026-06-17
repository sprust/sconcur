<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Request command: the request itself plus the per-request tuning.
 * Carries the mandatory hard execution limit (requestTimeoutMs), applied on the Go
 * side as a context deadline over the whole operation.
 *
 * Go: payloads.RequestParams (ext/internal/features/httpclient/payloads/payloads.go).
 */
readonly class RequestPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(
        protected string $method,
        protected string $url,
        protected array $headers,
        protected string $body,
        protected bool $streamBody,
        protected string $requestId,
        protected int $requestTimeoutMs,
        protected int $connectTimeoutMs,
        protected int $responseHeaderTimeoutMs,
        protected int $maxResponseBody,
        protected bool $followRedirects,
        protected int $maxRedirects,
        protected int $chunkSize,
        protected bool $verifyTls,
        protected int $maxIdleConns,
        protected int $maxIdleConnsPerHost,
        protected int $idleConnTimeoutMs,
        protected int $tlsHandshakeTimeoutMs,
        protected string $sinkPath = '',
        protected int $sinkMode = 0,
        protected int $sinkPerm = 0,
        protected int $downloadBufferSizeBytes = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'm'   => $this->method,
            'u'   => $this->url,
            'h'   => $this->headers,
            'b'   => $this->body,
            'sb'  => $this->streamBody,
            'rid' => $this->requestId,
            'rt'  => $this->requestTimeoutMs,
            'ct'  => $this->connectTimeoutMs,
            'rht' => $this->responseHeaderTimeoutMs,
            'mrb' => $this->maxResponseBody,
            'fr'  => $this->followRedirects,
            'mr'  => $this->maxRedirects,
            'cs'  => $this->chunkSize,
            'vt'  => $this->verifyTls,
            'mic' => $this->maxIdleConns,
            'mih' => $this->maxIdleConnsPerHost,
            'ict' => $this->idleConnTimeoutMs,
            'tht' => $this->tlsHandshakeTimeoutMs,
            'sp'  => $this->sinkPath,
            'sm'  => $this->sinkMode,
            'spm' => $this->sinkPerm,
            'dbs' => $this->downloadBufferSizeBytes,
        ];
    }
}
