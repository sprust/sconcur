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
    protected static ?int $counter = null;

    /** A 24-character hexadecimal id: 4 bytes of time, 5 random, 3 of counter. */
    public static function generate(): string
    {
        self::$counter ??= random_int(0, 0xFFFFFF);
        self::$counter = (self::$counter + 1) & 0xFFFFFF;

        return bin2hex(pack('N', time()))
            . bin2hex(random_bytes(5))
            . substr(bin2hex(pack('N', self::$counter)), 2);
    }
}
