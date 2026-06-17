<?php

declare(strict_types=1);

namespace SConcur\Features\File\Results;

use SConcur\Transport\MessagePackTransport;

/**
 * Result of a Stat command: the file size in bytes, the last-modification time in
 * milliseconds since the epoch, and the raw Unix mode bits.
 *
 * Go side encodes `{sz: size, mt: mtimeMs, md: mode}`.
 */
readonly class FileStat
{
    public function __construct(
        public int $size,
        public int $modifiedAtMs,
        public int $mode,
    ) {
    }

    public static function fromPayload(string $payload): self
    {
        $decoded = $payload === '' ? [] : MessagePackTransport::unpack($payload);

        return new self(
            size: (int) ($decoded['sz'] ?? 0),
            modifiedAtMs: (int) ($decoded['mt'] ?? 0),
            mode: (int) ($decoded['md'] ?? 0),
        );
    }
}
