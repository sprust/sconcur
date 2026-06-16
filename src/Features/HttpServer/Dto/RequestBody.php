<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Dto;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\HttpServer\RequestBodyTooLargeException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\FeatureExecutor;

/**
 * The request body. It is never buffered whole in the extension: the inline first
 * chunk arrives with the request, the rest is pulled on demand. Read it either
 * fully via contents() or piece by piece via read() — use one style per request,
 * since both consume the same one-shot stream.
 */
class RequestBody
{
    /** Marker the Go side returns when the body exceeds maxRequestBody. */
    private const string TOO_LARGE_MARKER = 'request body too large';

    /** Bytes already pulled from the source but not yet returned by read(). */
    private string $buffer = '';

    private bool $firstChunkConsumed = false;

    /** True once the streamed remainder is exhausted (or there is none). */
    private bool $streamFinished;

    private ?string $contents = null;

    /**
     * @param string $firstChunk the inline first chunk of the body
     * @param string $bodyKey    streaming key for the remainder, or '' if the whole
     *                           body is already in $firstChunk
     */
    public function __construct(
        private readonly string $firstChunk,
        private readonly string $bodyKey,
    ) {
        $this->streamFinished = $bodyKey === '';
    }

    /**
     * Returns up to $maxBytes bytes of the body (or the next whole source chunk
     * when $maxBytes is null), or null once the body is fully read. The inline
     * first chunk is served first, then the streamed remainder.
     *
     * @throws RequestBodyTooLargeException if the body exceeds the server limit
     */
    public function read(?int $maxBytes = null): ?string
    {
        $this->fillBuffer();

        if ($this->buffer === '') {
            return null;
        }

        if ($maxBytes === null || $maxBytes >= strlen($this->buffer)) {
            $chunk = $this->buffer;

            $this->buffer = '';

            return $chunk;
        }

        if ($maxBytes <= 0) {
            return '';
        }

        $chunk = substr($this->buffer, 0, $maxBytes);

        $this->buffer = substr($this->buffer, $maxBytes);

        return $chunk;
    }

    /**
     * Reads and returns the whole body as a string (bounded by the server's
     * maxRequestBody). Memoized.
     *
     * @throws RequestBodyTooLargeException if the body exceeds the server limit
     */
    public function contents(): string
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        $all = '';

        while (($chunk = $this->read()) !== null) {
            $all .= $chunk;
        }

        return $this->contents = $all;
    }

    /**
     * Ensures the buffer holds data, or that the body is exhausted: serves the
     * inline first chunk first, then pulls streamed chunks until one is non-empty.
     */
    private function fillBuffer(): void
    {
        if ($this->buffer !== '') {
            return;
        }

        if (!$this->firstChunkConsumed) {
            $this->firstChunkConsumed = true;
            $this->buffer             = $this->firstChunk;

            if ($this->buffer !== '') {
                return;
            }
        }

        while ($this->buffer === '' && !$this->streamFinished) {
            $result = $this->pullChunk();

            $this->streamFinished = !$result->hasNext;
            $this->buffer         = $result->payload;
        }
    }

    private function pullChunk(): TaskResultDto
    {
        try {
            return FeatureExecutor::next(taskKey: $this->bodyKey);
        } catch (TaskErrorException $exception) {
            if ($exception->getMessage() === self::TOO_LARGE_MARKER) {
                throw new RequestBodyTooLargeException(
                    message: self::TOO_LARGE_MARKER,
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }
}
