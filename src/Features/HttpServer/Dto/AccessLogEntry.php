<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Dto;

/**
 * One handled request, passed to the HttpServer access-log callback after the
 * response has been sent. Lets the caller print/ship a log line in any format.
 */
readonly class AccessLogEntry
{
    /**
     * @param float  $startedAt   unix timestamp (microtime) when handling began
     * @param string $method      request method
     * @param string $path        request path (without query)
     * @param int    $status      response status code
     * @param float  $executionMs wall time spent handling the request, in milliseconds
     */
    public function __construct(
        public float $startedAt,
        public string $method,
        public string $path,
        public int $status,
        public float $executionMs,
    ) {
    }
}
