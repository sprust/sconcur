<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * One HTTP request to send, plus the per-request tuning. Carries the mandatory
 * hard execution limit (requestTimeoutMs), applied on the Go side as a context
 * deadline over the whole operation.
 *
 * Go: payloads.RequestPayload (ext/internal/features/httpclient/payloads/payloads.go).
 */
readonly class RequestPayload implements PayloadInterface
{
    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(
        protected string $method,
        protected string $url,
        protected array $headers,
        protected string $body,
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
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::HttpClient;
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
        ];
    }
}
