<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * Sends the response a request-handler coroutine produced for a given request.
 *
 * Go: payloads.RespondPayload (ext/internal/features/httpserver/payloads/payloads.go).
 */
readonly class RespondPayload implements PayloadInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $requestId,
        private int $status,
        private array $headers,
        private string $body,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::HttpRespond;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $data = [
            'rid' => $this->requestId,
            'st'  => $this->status,
            'bd'  => $this->body,
        ];

        // Omit empty headers: an empty PHP array encodes as a MessagePack array,
        // which the Go side cannot decode into its headers map (it stays nil).
        if ($this->headers !== []) {
            $data['hd'] = $this->headers;
        }

        return $data;
    }
}
