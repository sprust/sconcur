<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Features\Amqp\AMQPDecimal;
use SConcur\Features\Amqp\AMQPException;
use SConcur\Features\Amqp\AMQPTimestamp;
use SConcur\Features\Amqp\AMQPValue;

/**
 * Carries an AMQP field table (queue and exchange arguments, message headers) across the
 * boundary in both directions.
 *
 * Two rules are the extension's, and both matter to what actually reaches the broker:
 *
 * - a key that is not a string is dropped with a warning at the top level, and turned into
 *   a string deeper down, so a header carrying a list keeps its values;
 * - a nested array with no string keys at all is an AMQP field array, not a table.
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
    /**
     * The key naming the kind of a tagged value. It starts with a NUL byte, which no AMQP
     * field name may contain, so an application's own header can never be mistaken for one.
     */
    protected const string KIND = "\x00amqp";

    /** A decimal: significand scaled down by 10^exponent. */
    protected const string KIND_DECIMAL = 'D';

    /** A timestamp, in seconds since the Unix epoch. */
    protected const string KIND_TIMESTAMP = 'T';

    /** How deep a table may nest, the limit the extension enforces. */
    protected const int MAX_DEPTH = 128;

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

            $encoded[$name] = static::encodeValue(value: $value, depth: 1);
        }

        return $encoded;
    }

    /**
     * Rebuilds the values of a field table that came back from the broker: the tagged maps
     * become AMQPDecimal and AMQPTimestamp again, everything else is already what it was.
     *
     * @param array<array-key, mixed> $table
     *
     * @return array<array-key, mixed>
     */
    public static function decode(array $table): array
    {
        $decoded = [];

        foreach ($table as $name => $value) {
            $decoded[$name] = static::decodeValue($value);
        }

        return $decoded;
    }

    /**
     * A nested table: unlike the top level, a key that is not a string is kept. PHP cannot
     * hold "0" as a string key — it normalizes it back to an integer — so the key travels
     * as it is and the Go side gives it its string form, which is what the extension sends.
     *
     * @param array<array-key, mixed> $table
     *
     * @return array<array-key, mixed>
     */
    protected static function encodeTable(array $table, int $depth): array
    {
        $encoded = [];

        foreach ($table as $name => $value) {
            $encoded[$name] = static::encodeValue(value: $value, depth: $depth + 1);
        }

        return $encoded;
    }

    /**
     * A nested array with no string keys: an AMQP field array, which travels as a
     * MessagePack list.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<mixed>
     */
    protected static function encodeArray(array $values, int $depth): array
    {
        $encoded = [];

        foreach ($values as $value) {
            $encoded[] = static::encodeValue(value: $value, depth: $depth + 1);
        }

        return $encoded;
    }

    /**
     * @throws AMQPException if the value nests deeper than the protocol allows
     */
    protected static function encodeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new AMQPException(
                message: 'Maximum serialization depth of ' . self::MAX_DEPTH
                    . ' reached while serializing value',
            );
        }

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
        if ($value instanceof AMQPValue) {
            return static::encodeValue(value: $value->toAmqpValue(), depth: $depth + 1);
        }

        if (!is_array($value)) {
            return $value;
        }

        return static::isFieldArray($value)
            ? static::encodeArray(values: $value, depth: $depth)
            : static::encodeTable(table: $value, depth: $depth);
    }

    /**
     * Whether an array is an AMQP field array rather than a table. The extension's rule:
     * no string key anywhere in it, whatever the integer keys are.
     *
     * @param array<array-key, mixed> $values
     */
    protected static function isFieldArray(array $values): bool
    {
        foreach ($values as $name => $value) {
            if (is_string($name)) {
                return false;
            }
        }

        return true;
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

        return static::decode($value);
    }
}
