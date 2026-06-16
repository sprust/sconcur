<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Dto;

use Psr\Http\Message\StreamInterface;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\HttpClient\HttpClientException;
use SConcur\Features\FeatureExecutor;
use SConcur\State;
use Throwable;

/**
 * The response body as a PSR-7 stream. It is never buffered whole in the
 * extension: the inline first chunk arrives with the response, the rest is pulled
 * on demand from Go (like the HTTP-server's RequestBody / a Mongo cursor).
 *
 * One-directional, read-only and not seekable: seek/rewind/write throw, as PSR-7
 * allows for non-rewindable streams. Inside a coroutine read() suspends it while a
 * chunk is fetched, so a slow server never blocks the other requests.
 */
class ResponseBodyStream implements StreamInterface
{
    /** Read granularity used by getContents()/__toString() when draining. */
    protected const int DRAIN_CHUNK_SIZE = 65_536;

    /** Bytes already pulled from the source but not yet returned by read(). */
    protected string $buffer = '';

    protected bool $firstChunkConsumed = false;

    /** True once the streamed remainder is exhausted (or there is none). */
    protected bool $streamFinished;

    /** Bytes already handed to the consumer (the read cursor position). */
    protected int $position = 0;

    /** True after close()/detach(): the stream is spent and reads return ''. */
    protected bool $detached = false;

    /** Memoized result of getContents() so repeat calls (and __toString) are stable. */
    protected ?string $cachedContents = null;

    /**
     * @param string   $firstChunk           the inline first chunk of the body
     * @param string   $bodyKey              streaming key for the remainder, or '' if the
     *                                       whole body is already in $firstChunk
     * @param int|null $size                 the response Content-Length, or null when unknown
     * @param bool     $throwOnToStringError whether __toString re-throws a read error (PSR-7
     *                                       says it must not; false turns it into a warning)
     */
    public function __construct(
        protected readonly string $firstChunk,
        protected readonly string $bodyKey,
        protected readonly ?int $size = null,
        protected readonly bool $throwOnToStringError = true,
    ) {
        $this->streamFinished = $bodyKey === '';
    }

    public function close(): void
    {
        $this->releaseTask();

        $this->detached       = true;
        $this->streamFinished = true;
        $this->buffer         = '';
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        if ($this->detached) {
            return true;
        }

        $this->fillBuffer();

        return $this->buffer === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new HttpClientException('The response body stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new HttpClientException('The response body stream is not rewindable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new HttpClientException('The response body stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->detached || $length <= 0) {
            return '';
        }

        $this->fillBuffer();

        if ($this->buffer === '') {
            return '';
        }

        if ($length >= strlen($this->buffer)) {
            $chunk = $this->buffer;

            $this->buffer = '';
        } else {
            $chunk = substr($this->buffer, 0, $length);

            $this->buffer = substr($this->buffer, $length);
        }

        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        if ($this->cachedContents !== null) {
            return $this->cachedContents;
        }

        $contents = '';

        while (($chunk = $this->read(self::DRAIN_CHUNK_SIZE)) !== '') {
            $contents .= $chunk;
        }

        return $this->cachedContents = $contents;
    }

    /**
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function getMetadata(?string $key = null)
    {
        $metadata = ['seekable' => false];

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }

    /**
     * Ensures the buffer holds data, or that the body is exhausted: serves the
     * inline first chunk first, then pulls streamed chunks until one is non-empty.
     */
    protected function fillBuffer(): void
    {
        if ($this->buffer !== '' || $this->detached) {
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

    protected function pullChunk(): TaskResultDto
    {
        return FeatureExecutor::next(taskKey: $this->bodyKey);
    }

    /**
     * Releases the synchronous flow owning the response when the body is abandoned
     * before exhaustion (early break / object destruction). No-op in async mode,
     * after normal completion, and when the whole body was inline (no bodyKey).
     */
    protected function releaseTask(): void
    {
        if ($this->bodyKey !== '') {
            State::releaseSyncTaskFlow($this->bodyKey);
        }
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable $exception) {
            if ($this->throwOnToStringError) {
                throw $exception;
            }

            trigger_error(
                sprintf('%s::__toString failed: %s', self::class, $exception->getMessage()),
                E_USER_WARNING,
            );

            return '';
        }
    }

    public function __destruct()
    {
        $this->releaseTask();
    }
}
