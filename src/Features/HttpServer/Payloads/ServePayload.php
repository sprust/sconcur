<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * Starts the HTTP listener bound to the given address (e.g. "0.0.0.0:8080").
 *
 * Go: payloads.ServePayload (ext/internal/features/httpserver/payloads/payloads.go).
 */
readonly class ServePayload implements PayloadInterface
{
    public function __construct(
        private string $address,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::HttpServe;
    }

    /**
     * @return array<string, string>
     */
    public function getData(): array
    {
        return [
            'ad' => $this->address,
        ];
    }
}
