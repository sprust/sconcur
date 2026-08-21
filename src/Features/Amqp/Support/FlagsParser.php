<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

/**
 * The only place in the feature where the AMQP_* bit masks live. The calque takes flags
 * as an integer because ext-amqp does; everything below this class works with named
 * boolean fields, as the rest of the project does.
 */
readonly class FlagsParser
{
    /**
     * Whether one flag is set. A null mask means "no flags", the same as AMQP_NOPARAM.
     */
    public static function has(?int $flags, int $flag): bool
    {
        if ($flags === null) {
            return false;
        }

        return ($flags & $flag) === $flag;
    }
}
