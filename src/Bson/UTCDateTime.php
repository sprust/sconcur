<?php

declare(strict_types=1);

namespace SConcur\Bson;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use SConcur\Bson\Exceptions\InvalidBsonValueException;
use Stringable;

/**
 * BSON UTC datetime, mirroring MongoDB\BSON\UTCDateTime: milliseconds since the
 * Unix epoch, which may be negative.
 */
readonly class UTCDateTime implements Type, Stringable, JsonSerializable
{
    // Public on purpose: MessagePack mangles the name of a protected property the
    // way serialize() does ("\0*\0data"), and the Go side writes plain names. The
    // class is readonly, so the value object stays immutable regardless.
    public int $epochMs;

    public function __construct(DateTimeInterface|Int64|string|int|float|null $milliseconds = null)
    {
        $this->epochMs = match (true) {
            $milliseconds === null                     => (int) (microtime(true) * 1000),
            $milliseconds instanceof DateTimeInterface => self::fromDateTime($milliseconds),
            $milliseconds instanceof Int64             => $milliseconds->toInt(),
            is_float($milliseconds)                    => (int) $milliseconds,
            is_int($milliseconds)                      => $milliseconds,
            default                                    => self::fromString($milliseconds),
        };
    }

    public function toDateTime(): DateTime
    {
        return DateTime::createFromImmutable($this->toDateTimeImmutable());
    }

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        $seconds      = intdiv($this->epochMs, 1000);
        $milliseconds = $this->epochMs - $seconds * 1000;

        if ($milliseconds < 0) {
            $seconds--;
            $milliseconds += 1000;
        }

        return DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%03d', $seconds, $milliseconds),
            new DateTimeZone('UTC'),
        )->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array<string, array<string, string>> */
    public function jsonSerialize(): array
    {
        return ['$date' => ['$numberLong' => (string) $this->epochMs]];
    }

    /** A string is a whole number of milliseconds, as it is for the driver. */
    protected static function fromString(string $milliseconds): int
    {
        $number = IntegerParser::parse($milliseconds);

        if ($number === null) {
            throw new InvalidBsonValueException(
                message: sprintf(
                    'Error parsing "%s" as 64-bit integer for %s initialization',
                    $milliseconds,
                    self::class,
                ),
            );
        }

        return $number;
    }

    protected static function fromDateTime(DateTimeInterface $dateTime): int
    {
        return $dateTime->getTimestamp() * 1000 + intdiv((int) $dateTime->format('u'), 1000);
    }

    public function __toString(): string
    {
        return (string) $this->epochMs;
    }
}
