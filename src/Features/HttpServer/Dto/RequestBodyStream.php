<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Dto;

use Psr\Http\Message\StreamInterface;
use SConcur\Exceptions\HttpServer\RequestBodyStreamException;
use Throwable;

/**
 * The request body as a PSR-7 stream, wrapping the streaming RequestBody. It is
 * never buffered whole in the extension: the inline first chunk arrives with the
 * request, the rest is pulled on demand from Go. So the handler receives an
 * ordinary ServerRequestInterface whose getBody() streams.
 *
 * One-directional, read-only and not seekable: seek/rewind/write throw, as PSR-7
 * allows for non-rewindable streams. Inside a coroutine read() suspends it while a
 * chunk is fetched, so a slow client never blocks the other requests. A body that
 * exceeds the server limit surfaces as RequestBodyTooLargeException out of read()/
 * getContents() (the handler may let it bubble up — the framework maps it to 413).
 */
class RequestBodyStream implements StreamInterface
{
    /** Read granularity used by getContents()/__toString() when draining. */
    protected const int DRAIN_CHUNK_SIZE = 65_536;

    /** Bytes already pulled from the source but not yet returned by read(). */
    protected string $buffer = '';

    /** True once the wrapped body is exhausted (no more chunks to pull). */
    protected bool $finished = false;

    /** Bytes already handed to the consumer (the read cursor position). */
    protected int $position = 0;

    /** True after close()/detach(): the stream is spent and reads return ''. */
    protected bool $detached = false;

    /** Memoized result of getContents() so repeat calls (and __toString) are stable. */
    protected ?string $cachedContents = null;

    public function __construct(
        protected readonly RequestBody $body,
    ) {
    }

    public function close(): void
    {
        $this->detached = true;
        $this->finished = true;
        $this->buffer   = '';
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    /** The body length is unknown up front (it is streamed), so the size is null. */
    public function getSize(): ?int
    {
        return null;
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
        throw new RequestBodyStreamException('The request body stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RequestBodyStreamException('The request body stream is not rewindable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RequestBodyStreamException('The request body stream is not writable.');
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
     * Ensures the buffer holds data, or that the body is exhausted: pulls the next
     * non-empty chunk from the wrapped RequestBody.
     */
    protected function fillBuffer(): void
    {
        if ($this->buffer !== '' || $this->finished || $this->detached) {
            return;
        }

        $chunk = $this->body->read();

        if ($chunk === null) {
            $this->finished = true;

            return;
        }

        $this->buffer = $chunk;
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable $exception) {
            trigger_error(
                sprintf('%s::__toString failed: %s', self::class, $exception->getMessage()),
                E_USER_WARNING,
            );

            return '';
        }
    }
}
