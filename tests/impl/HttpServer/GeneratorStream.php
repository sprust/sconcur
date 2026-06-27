<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\HttpServer;

use Generator;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only PSR-7 stream backed by a generator of chunks. Returning a response
 * whose body is one of these makes the HTTP server stream it: getSize() is null, so
 * the framework drains it chunk by chunk (head/chunk/end, chunked transfer). The
 * generator may suspend the coroutine between chunks (Sleeper, Mongodb, ...), which
 * is how the demo server streams with async work in between.
 *
 * Demo/test helper — it shows how a user expresses a streamed/SSE response purely
 * through the PSR-7 StreamInterface, so the library itself ships no streaming DTO.
 */
class GeneratorStream implements StreamInterface
{
    /** Bytes pulled from the generator but not yet returned by read(). */
    private string $buffer = '';

    private bool $started = false;

    private bool $finished = false;

    public function __construct(
        private readonly Generator $chunks,
    ) {
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
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

        return $chunk;
    }

    public function eof(): bool
    {
        $this->fillBuffer();

        return $this->buffer === '';
    }

    public function getContents(): string
    {
        $contents = '';

        while (($chunk = $this->read(65_536)) !== '') {
            $contents .= $chunk;
        }

        return $contents;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function tell(): int
    {
        throw new RuntimeException('The generator stream does not track a position.');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('The generator stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('The generator stream is not rewindable.');
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The generator stream is not writable.');
    }

    public function close(): void
    {
        $this->finished = true;
        $this->buffer   = '';
    }

    public function detach()
    {
        $this->close();

        return null;
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

    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * Pulls the next non-empty chunk from the generator. The code that runs between
     * yields (e.g. a Sleeper sleep) executes here, after the previous chunk has been
     * flushed — so async work happens between flushes, not before the first.
     */
    private function fillBuffer(): void
    {
        if ($this->buffer !== '' || $this->finished) {
            return;
        }

        while ($this->buffer === '' && !$this->finished) {
            if (!$this->started) {
                // A generator runs to its first yield on the first valid()/current().
                $this->started = true;
            } else {
                $this->chunks->next();
            }

            if (!$this->chunks->valid()) {
                $this->finished = true;

                return;
            }

            $this->buffer = (string) $this->chunks->current();
        }
    }
}
