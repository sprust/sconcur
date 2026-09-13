<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;

/**
 * Command arguments on their way to the wire.
 *
 * Redis takes byte strings and nothing else, so the conversion happens here, once, and
 * only for the three types where it is unambiguous. A boolean or a null is refused rather
 * than converted: phpredis writes false as an empty string, and a value the application
 * never asked for only ever shows up later, in the data.
 */
readonly class Arguments
{
    /**
     * @param list<mixed> $arguments
     *
     * @return list<string>
     */
    public static function encode(array $arguments): array
    {
        $encoded = [];

        foreach ($arguments as $position => $argument) {
            $encoded[] = static::encodeOne(argument: $argument, position: $position);
        }

        return $encoded;
    }

    protected static function encodeOne(mixed $argument, int|string $position): string
    {
        if (is_string($argument)) {
            return $argument;
        }

        if (is_int($argument)) {
            return (string) $argument;
        }

        if (is_float($argument)) {
            if (is_nan($argument) || is_infinite($argument)) {
                throw new InvalidRedisArgumentException(
                    message: "Argument #$position is not a finite number.",
                );
            }

            return static::formatFloat($argument);
        }

        $type = get_debug_type($argument);

        throw new InvalidRedisArgumentException(
            message: "Argument #$position is a $type; a Redis argument must be a string, an int or a float. "
                . 'Serialize the value yourself — this feature stores bytes and converts nothing.',
        );
    }

    /**
     * The shortest decimal form that reads back as the same double.
     *
     * Neither a string cast nor var_export can be used: the first rounds to the
     * `precision` ini setting and the second to `serialize_precision`, so the
     * value a sorted-set score arrives with would depend on the php.ini of the
     * machine it was written on. Widening every float to 17 digits would be
     * exact but would send `0.10000000000000001` for `0.1`, so the shortest
     * exact form is found instead — at most seventeen cheap formats, and the
     * common values land in one or two.
     *
     * An exponent form is only taken when no fixed one reads back as the same
     * double. `%G` reaches for an exponent as soon as the decimal exponent
     * reaches the precision, so the shortest form of `60.0` is `6E+1` — and that
     * is not a number Redis takes where it takes an integer: `EXPIRE k 6E+1` is
     * refused outright, and `SET k 1E+2` stores those five bytes where `100` was
     * meant. Only magnitudes no fixed form can carry (`1e-7`, `1e300`) end up in
     * exponent form, and a score is the only argument they can be.
     */
    protected static function formatFloat(float $value): string
    {
        $exponential = null;

        for ($digits = 1; $digits <= 17; ++$digits) {
            $formatted = sprintf('%.' . $digits . 'G', $value);

            if ((float) $formatted !== $value) {
                continue;
            }

            if (stripos($formatted, 'E') === false) {
                return $formatted;
            }

            $exponential ??= $formatted;
        }

        return $exponential ?? sprintf('%.17G', $value);
    }
}
