<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Exceptions\Amqp\InvalidAmqpValueException;
use SConcur\Features\Amqp\AmqpValue;
use SConcur\Features\Amqp\Decimal;
use SConcur\Features\Amqp\Timestamp;

/**
 * Carries an AMQP field table — queue and exchange arguments, message headers — across the
 * boundary in both directions.
 *
 * Two rules decide what reaches the broker:
 *
 * - a key that is not a string is dropped with a warning at the top level and stringified
 *   deeper down, so a header carrying a list keeps its values;
 * - a nested array with no string key anywhere is an AMQP field array, not a table.
 *
 * A decimal and a timestamp are the two values MessagePack has no type for. They travel in
 * a tagged map the Go side turns into the real field value and back, because flattening
 * them would change the type of a header for every other client reading the same queue.
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
     * The first second a PHP int cannot hold: 2^63.
     *
     * Compared against rather than PHP_INT_MAX, because a float comparison promotes
     * PHP_INT_MAX to 2^63 anyway — so exactly 2^63 passed a `> PHP_INT_MAX` test and then
     * wrapped to PHP_INT_MIN on the cast, putting a timestamp from before 1970 on the wire.
     */
    protected const float MAX_SENDABLE_SECONDS = 9223372036854775808.0;

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

            $encoded[$name] = static::encodeValue(
                value: $value,
                depth: 1,
            );
        }

        return $encoded;
    }

    /**
     * Rebuilds the values of a field table that came back from the broker: the tagged maps
     * become Decimal and Timestamp again, everything else is already what it was.
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
            $encoded[$name] = static::encodeValue(
                value: $value,
                depth: $depth + 1,
            );
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
            $encoded[] = static::encodeValue(
                value: $value,
                depth: $depth + 1,
            );
        }

        return $encoded;
    }

    protected static function encodeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidAmqpValueException(
                message: 'Maximum serialization depth of ' . self::MAX_DEPTH
                    . ' reached while serializing value',
            );
        }

        if ($value instanceof Decimal) {
            return [
                self::KIND => self::KIND_DECIMAL,
                'e'        => $value->exponent,
                's'        => $value->significand,
            ];
        }

        if ($value instanceof Timestamp) {
            // AMQP counts unsigned 64-bit seconds, which is what Timestamp::MAX_SECONDS
            // allows, but neither a PHP int nor the Go time the field is built from can
            // hold the upper half of that range. A cast would wrap it into a negative
            // number and the message would carry a timestamp from before 1970, so the
            // limit is stated instead of silently crossed (docs/amqp.md lists it).
            if ($value->seconds >= self::MAX_SENDABLE_SECONDS) {
                throw new InvalidAmqpValueException(
                    message: 'Timestamp exceeds ' . PHP_INT_MAX . ' and cannot be sent.',
                );
            }

            return [
                self::KIND => self::KIND_TIMESTAMP,
                'v'        => (int) $value->seconds,
            ];
        }

        // An application's own AmqpValue names what it stands for through toAmqpValue(),
        // which may in turn hand over one of the two above.
        if ($value instanceof AmqpValue) {
            return static::encodeValue(value: $value->toAmqpValue(), depth: $depth + 1);
        }

        if (!is_array($value)) {
            return $value;
        }

        return static::isFieldArray($value)
            ? static::encodeArray(
                values: $value,
                depth: $depth,
            )
            : static::encodeTable(
                table: $value,
                depth: $depth,
            );
    }

    /**
     * Whether an array is an AMQP field array rather than a table: no string key anywhere
     * in it, whatever the integer keys are.
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

        // A value the object refuses is handed over as the plain number it was on the
        // wire. Whoever published it is not necessarily this library, and a header no
        // Decimal can hold must not make the whole delivery unreadable: the
        // exception would escape while the delivery is being built, kill the consumer,
        // and leave the message to be redelivered forever.
        if ($kind === self::KIND_DECIMAL) {
            $significand = (int) ($value['s'] ?? 0);

            try {
                return new Decimal(
                    exponent: (int) ($value['e'] ?? 0),
                    significand: $significand,
                );
            } catch (InvalidAmqpValueException) {
                return $significand;
            }
        }

        if ($kind === self::KIND_TIMESTAMP) {
            $seconds = (float) ($value['v'] ?? 0);

            try {
                return new Timestamp($seconds);
            } catch (InvalidAmqpValueException) {
                return (int) $seconds;
            }
        }

        return static::decode($value);
    }
}
