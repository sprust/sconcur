<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Determines the number of CPU cores, used as the default worker count. Linux-only
 * (like the rest of the library): tries nproc, then /proc/cpuinfo, then falls back
 * to 1.
 */
class Cpu
{
    public static function count(): int
    {
        return self::fromNproc()
            ?? self::fromCpuinfo()
            ?? 1;
    }

    protected static function fromNproc(): ?int
    {
        $output = @shell_exec('nproc 2>/dev/null');

        if (!is_string($output)) {
            return null;
        }

        $count = (int) trim($output);

        return $count > 0 ? $count : null;
    }

    protected static function fromCpuinfo(): ?int
    {
        $contents = @file_get_contents('/proc/cpuinfo');

        if (!is_string($contents)) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', $contents);

        return $count > 0 ? $count : null;
    }
}
