<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use SConcur\Exceptions\Telemetry\FrameTooLargeException;

/**
 * Decodes the length-prefix framing used on the telemetry socket — the exact mirror
 * of the Go writer (ext/internal/socket/frame.go): a 4-byte big-endian length
 * followed by that many payload bytes. Pulls every complete frame out of a
 * per-connection buffer and returns the unconsumed tail (a partial next frame) so
 * the caller can append more bytes and decode again.
 */
class FrameCodec
{
    protected const HEADER_BYTES = 4;

    /**
     * Extracts every complete frame from $buffer. Returns the list of frame bodies
     * and the leftover buffer (bytes of an incomplete trailing frame, possibly "").
     *
     * @return array{0: list<string>, 1: string}
     */
    public static function extractFrames(string $buffer, int $maxFrameBytes): array
    {
        $frames = [];
        $offset = 0;
        $length = strlen($buffer);

        while ($length - $offset >= self::HEADER_BYTES) {
            $header = unpack('N', substr($buffer, $offset, self::HEADER_BYTES));

            if ($header === false) {
                break;
            }

            $frameLength = $header[1];

            if ($maxFrameBytes > 0 && $frameLength > $maxFrameBytes) {
                throw new FrameTooLargeException(
                    message: sprintf('telemetry frame of %d bytes exceeds the %d limit', $frameLength, $maxFrameBytes),
                );
            }

            if ($length - $offset - self::HEADER_BYTES < $frameLength) {
                // The body has not fully arrived yet — keep it for the next read.
                break;
            }

            $frames[] = substr($buffer, $offset + self::HEADER_BYTES, $frameLength);
            $offset += self::HEADER_BYTES + $frameLength;
        }

        return [$frames, substr($buffer, $offset)];
    }
}
