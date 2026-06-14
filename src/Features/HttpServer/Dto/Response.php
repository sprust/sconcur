<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Dto;

/**
 * The response a request handler returns; sent back to the client by the Go
 * server (payloads.RespondPayload).
 */
readonly class Response
{
    /**
     * @param array<string, string|array<int, string>> $headers a header value may be
     *                                                          a single string or a list of strings (e.g. several Set-Cookie entries)
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }
}
