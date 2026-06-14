<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Dto;

use Closure;

/**
 * A streamed response: the status and headers are sent first, then the writer
 * closure pushes the body in chunks (each flushed to the client) via the
 * ResponseStream it receives. Return one of these from a handler instead of a
 * Response to stream (chunked transfer, SSE, ...). The writer runs in the request
 * coroutine, so it may do async work (Sleeper, Mongodb, ...) between chunks.
 */
readonly class StreamedResponse
{
    /**
     * @param Closure(ResponseStream): void            $writer
     * @param array<string, string|array<int, string>> $headers
     */
    public function __construct(
        public Closure $writer,
        public int $status = 200,
        public array $headers = [],
    ) {
    }
}
