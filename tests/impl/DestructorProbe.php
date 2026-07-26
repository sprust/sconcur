<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

/**
 * Counts destructions: stress tests assert that unwinding coroutines (stop,
 * shutdown, preemption) releases locals — their destructors actually run.
 */
class DestructorProbe
{
    public static int $destroyedCount = 0;

    public function __destruct()
    {
        ++static::$destroyedCount;
    }
}
