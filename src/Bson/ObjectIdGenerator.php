<?php

declare(strict_types=1);

namespace SConcur\Bson;

/**
 * Builds new ObjectId values.
 *
 * Generation lives here rather than on ObjectId itself because the counter the
 * BSON specification requires is process-wide mutable state, and a readonly class
 * cannot hold it.
 */
class ObjectIdGenerator
{
    /**
     * The random part is drawn once per process, not once per id, exactly as the
     * driver does it: with the timestamp and the process value fixed, the counter
     * is what orders two ids taken inside the same second. Rerolling the random
     * bytes every time would leave that order to chance, and sorting by _id is how
     * insertion order is usually read back.
     */
    protected static ?string $processRandom = null;

    protected static ?int $counter = null;

    /** A 24-character hexadecimal id: 4 bytes of time, 5 random, 3 of counter. */
    public static function generate(): string
    {
        self::$processRandom ??= random_bytes(5);

        self::$counter ??= random_int(0, 0xFFFFFF);
        self::$counter = (self::$counter + 1) & 0xFFFFFF;

        return bin2hex(pack('N', time()))
            . bin2hex(self::$processRandom)
            . substr(bin2hex(pack('N', self::$counter)), 2);
    }
}
