<?php

declare(strict_types=1);

namespace SConcur\Bson;

/**
 * Reads the string forms the BSON value objects accept.
 *
 * A cast alone would not do: PHP clamps an overflowing numeric string to
 * PHP_INT_MAX instead of failing, and it turns anything unparsable into 0. The
 * driver rejects both, so a value that cannot be represented has to be told apart
 * from one that can.
 */
class IntegerParser
{
    /** Returns null when the string is not a whole number that fits in 64 bits. */
    public static function parse(string $value): ?int
    {
        $trimmed = trim($value);

        if (preg_match('/^[+-]?\d+$/', $trimmed) !== 1) {
            return null;
        }

        $number = (int) $trimmed;

        return (string) $number === self::normalize($trimmed) ? $number : null;
    }

    /** Drops the sign of a zero, a leading plus and leading zeros, as (string) (int) does. */
    protected static function normalize(string $value): string
    {
        $isNegative = str_starts_with($value, '-');
        $digits     = ltrim(ltrim($value, '+-'), '0');

        if ($digits === '') {
            return '0';
        }

        return ($isNegative ? '-' : '') . $digits;
    }
}
