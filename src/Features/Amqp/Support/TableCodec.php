<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Features\Amqp\AMQPDecimal;
use SConcur\Features\Amqp\AMQPTimestamp;
use SConcur\Features\Amqp\AMQPValue;

/**
 * Carries an AMQP field table (queue and exchange arguments, message headers) across the
 * boundary in both directions.
 *
 * Most values are scalars MessagePack already knows. The two that are not — a decimal and
 * a timestamp — have field kinds of their own in AMQP 0-9-1, and the extension writes them
 * as such, so they travel in a tagged map the Go side turns into the real field value and
 * turns back on the way home. Flattening them into a float and an integer would change the
 * type of a header for every other client reading the same queue, and would hand the
 * application a scalar where ext-amqp hands it an object.
 *
 * Go: the tagged* constants (ext/internal/features/amqp/values.go).
 */
readonly class TableCodec
{
    /** The key naming the kind of a tagged value. */
    protected const string KIND = '__amqp';

    /** A decimal: significand scaled down by 10^exponent. */
    protected const string KIND_DECIMAL = 'D';

    /** A timestamp, in seconds since the Unix epoch. */
    protected const string KIND_TIMESTAMP = 'T';

    /** How many times a chain of AMQPValue::toAmqpValue() calls is followed. */
    protected const int MAX_VALUE_DEPTH = 8;

    /**
     * Prepares a field table for the wire. A key that is not a string is dropped with a
     * warning, as the extension drops it: a table whose keys came from user data must not
     * cost the whole message.
     *
     * @param array<array-key, mixed> $table
     *
     * @return array<string, mixed>
     */
    public static function encode(array $table): array
    {
        $encoded = [];

        foreach ($table as $name => $value) {
            if (!is_string($name)) {
                trigger_error("Ignoring non-string header field '$name'", E_USER_WARNING);

                continue;
            }

            $encoded[$name] = static::encodeValue($value);
        }

        return $encoded;
    }

    /**
     * Rebuilds the values of a field table that came back from the broker: the tagged maps
     * become AMQPDecimal and AMQPTimestamp again, everything else is already what it was.
     *
     * @param array<string, mixed> $table
     *
     * @return array<string, mixed>
     */
    public static function decode(array $table): array
    {
        $decoded = [];

        foreach ($table as $name => $value) {
            $decoded[$name] = static::decodeValue($value);
        }

        return $decoded;
    }

    protected static function encodeValue(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof AMQPDecimal) {
            return [
                self::KIND => self::KIND_DECIMAL,
                'e'        => $value->getExponent(),
                's'        => $value->getSignificand(),
            ];
        }

        if ($value instanceof AMQPTimestamp) {
            return [
                self::KIND => self::KIND_TIMESTAMP,
                'v'        => (int) $value->getTimestamp(),
            ];
        }

        // An application's own AMQPValue names what it stands for through toAmqpValue(),
        // which may in turn hand over one of the two above.
        if ($value instanceof AMQPValue && $depth < self::MAX_VALUE_DEPTH) {
            return static::encodeValue($value->toAmqpValue(), $depth + 1);
        }

        if (is_array($value)) {
            return static::encode($value);
        }

        return $value;
    }

    protected static function decodeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $kind = $value[self::KIND] ?? null;

        if ($kind === self::KIND_DECIMAL) {
            return new AMQPDecimal(
                exponent: (int) ($value['e'] ?? 0),
                significand: (int) ($value['s'] ?? 0),
            );
        }

        if ($kind === self::KIND_TIMESTAMP) {
            return new AMQPTimestamp((float) ($value['v'] ?? 0));
        }

        /** @var array<string, mixed> $value */
        return static::decode($value);
    }
}
