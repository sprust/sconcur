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
     * @param RequestBody                       $body       read fully with $body->contents() or chunk by chunk
     *                                                      with $body->read() (streamed, never buffered whole)
     * @param string                            $remoteAddr client "ip:port" as seen by the server
     * @param string                            $host       the request Host header / authority
     * @param string                            $proto      HTTP protocol version, e.g. "HTTP/1.1"
     */
    public function __construct(
        public string $requestId,
        public string $method,
        public string $path,
        public string $query,
        public array $headers,
        public RequestBody $body,
        public string $remoteAddr = '',
        public string $host = '',
        public string $proto = '',
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
            body: new RequestBody(
                firstChunk: (string) ($data['bd'] ?? ''),
                bodyKey: (string) ($data['bk'] ?? ''),
            ),
            remoteAddr: (string) ($data['ra'] ?? ''),
            host: (string) ($data['ho'] ?? ''),
            proto: (string) ($data['pr'] ?? ''),
        );
    }
}
