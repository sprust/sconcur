<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * One write a request-handler coroutine sends back for a given request: either a
 * one-shot full response, or the head/chunk/end of a streamed one. The op field
 * tells the Go side which.
 *
 * Go: payloads.RespondPayload (ext/internal/features/httpserver/payloads/payloads.go).
 */
readonly class RespondPayload implements PayloadInterface
{
    /** One-shot response: status + headers + body in a single write. */
    public const int OP_FULL = 0;

    /** Stream start: status + headers, flushed to the client. */
    public const int OP_HEAD = 1;

    /** Stream body chunk, flushed to the client. */
    public const int OP_CHUNK = 2;

    /** Stream end: finish the response. */
    public const int OP_END = 3;

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    private function __construct(
        private string $requestId,
        private int $op,
        private int $status,
        private array $headers,
        private string $body,
    ) {
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public static function full(string $requestId, int $status, array $headers, string $body): self
    {
        return new self(
            requestId: $requestId,
            op: self::OP_FULL,
            status: $status,
            headers: $headers,
            body: $body,
        );
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public static function head(string $requestId, int $status, array $headers): self
    {
        return new self(
            requestId: $requestId,
            op: self::OP_HEAD,
            status: $status,
            headers: $headers,
            body: '',
        );
    }

    public static function chunk(string $requestId, string $body): self
    {
        return new self(
            requestId: $requestId,
            op: self::OP_CHUNK,
            status: 0,
            headers: [],
            body: $body,
        );
    }

    public static function end(string $requestId): self
    {
        return new self(
            requestId: $requestId,
            op: self::OP_END,
            status: 0,
            headers: [],
            body: '',
        );
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
            'op'  => $this->op,
            'st'  => $this->status,
            'bd'  => $this->body,
        ];

        // Normalize each header to a list of strings so the Go side (map[string]
        // []string) decodes it uniformly, whether the handler gave a single string
        // or several values. Omit empty headers: an empty PHP array encodes as a
        // MessagePack array, which the Go side cannot decode into its map (stays nil).
        if ($this->headers !== []) {
            $headers = [];

            foreach ($this->headers as $name => $value) {
                $headers[$name] = is_array($value) ? array_values($value) : [$value];
            }

            $data['hd'] = $headers;
        }

        return $data;
    }
}
