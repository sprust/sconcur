<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Dto;

use SConcur\Transport\MessagePackTransport;

/**
 * An incoming HTTP request, decoded from the streaming payload the Go server
 * emits (payloads.RequestEvent).
 */
readonly class Request
{
    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(
        public string $requestId,
        public string $method,
        public string $path,
        public string $query,
        public array $headers,
        public string $body,
    ) {
    }

    public static function fromPayload(string $payload): self
    {
        /** @var array<string, mixed> $data */
        $data = MessagePackTransport::unpack($payload);

        // An empty header map decodes to stdClass (a MessagePack quirk), and
        // nested values may too; normalize to array<string, array<int, string>>.
        $headers = [];

        foreach ((array) ($data['hd'] ?? []) as $name => $values) {
            $headers[(string) $name] = array_values((array) $values);
        }

        return new self(
            requestId: (string) ($data['rid'] ?? ''),
            method: (string) ($data['mt'] ?? ''),
            path: (string) ($data['pt'] ?? ''),
            query: (string) ($data['qr'] ?? ''),
            headers: $headers,
            body: (string) ($data['bd'] ?? ''),
        );
    }
}
