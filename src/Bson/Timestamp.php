<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use Stringable;

/**
 * BSON internal timestamp, mirroring MongoDB\BSON\Timestamp: seconds since the
 * Unix epoch plus an ordinal within that second. This is the replication type,
 * not a date — use UTCDateTime for dates.
 */
readonly class Timestamp implements Type, Stringable, JsonSerializable
{
    // Public on purpose: MessagePack mangles the name of a protected property the
    // way serialize() does ("\0*\0data"), and the Go side writes plain names. The
    // class is readonly, so the value object stays immutable regardless.
    public int $increment;
    public int $epochSeconds;

    public function __construct(string|int $increment, string|int $timestamp)
    {
        $this->increment    = (int) $increment;
        $this->epochSeconds = (int) $timestamp;
    }

    public function getIncrement(): int
    {
        return $this->increment;
    }

    public function getTimestamp(): int
    {
        return $this->epochSeconds;
    }

    /** @return array<string, array<string, int>> */
    public function jsonSerialize(): array
    {
        return [
            '$timestamp' => [
                't' => $this->epochSeconds,
                'i' => $this->increment,
            ],
        ];
    }

    public function __toString(): string
    {
        return sprintf('[%d:%d]', $this->increment, $this->epochSeconds);
    }
}
