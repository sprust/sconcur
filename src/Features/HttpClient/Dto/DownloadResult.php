<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Dto;

/**
 * The result of HttpClient::download(): the response status, the response headers
 * exactly as the server returned them, the number of bytes actually written to the
 * file (the authoritative size — measured by io.Copy on the Go side, independent of
 * any Content-Length header) and how long the download took.
 */
readonly class DownloadResult
{
    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public int $filesizeBytes,
        public int $executionMs,
    ) {
    }
}
