<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * Starts the HTTP listener bound to the given address (e.g. "0.0.0.0:8080") with
 * the server tuning (timeouts in milliseconds, body limit in bytes).
 *
 * Go: payloads.ServePayload (ext/internal/features/httpserver/payloads/payloads.go).
 */
readonly class ServePayload implements PayloadInterface
{
    public function __construct(
        private string $address,
        private int $readHeaderTimeoutMs,
        private int $readTimeoutMs,
        private int $writeTimeoutMs,
        private int $idleTimeoutMs,
        private int $shutdownTimeoutMs,
        private int $maxRequestBody,
        private int $maxConcurrency,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::HttpServe;
    }

    /**
     * @return array<string, int|string>
     */
    public function getData(): array
    {
        return [
            'ad'  => $this->address,
            'rht' => $this->readHeaderTimeoutMs,
            'rt'  => $this->readTimeoutMs,
            'wt'  => $this->writeTimeoutMs,
            'it'  => $this->idleTimeoutMs,
            'sht' => $this->shutdownTimeoutMs,
            'mrb' => $this->maxRequestBody,
            'mc'  => $this->maxConcurrency,
        ];
    }
}
