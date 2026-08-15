<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use SConcur\Bson\Exceptions\InvalidBsonValueException;
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
        $this->increment    = self::toUnsigned32(value: $increment, name: 'increment');
        $this->epochSeconds = self::toUnsigned32(value: $timestamp, name: 'timestamp');
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

    /**
     * BSON stores both halves as unsigned 32-bit fields, so a value outside that
     * range is rejected here rather than wrapped on the way to the collection.
     */
    protected static function toUnsigned32(string|int $value, string $name): int
    {
        $number = is_int($value) ? $value : IntegerParser::parse($value);

        if ($number === null) {
            throw new InvalidBsonValueException(
                message: sprintf(
                    'Error parsing "%s" as 64-bit integer %s for %s initialization',
                    $value,
                    $name,
                    self::class,
                ),
            );
        }

        if ($number < 0 || $number > 0xFFFFFFFF) {
            throw new InvalidBsonValueException(
                message: sprintf('Expected %s to be an unsigned 32-bit integer, %s given', $name, $value),
            );
        }

        return $number;
    }

    public function __toString(): string
    {
        return sprintf('[%d:%d]', $this->increment, $this->epochSeconds);
    }
}
