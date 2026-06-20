<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Where the master writes its journal (lifecycle events and captured worker output):
 * a daily rotating file, the master's STDOUT (so `docker logs`/journald collect it),
 * or both. Default is File.
 */
enum LogTarget: string
{
    public function toFile(): bool
    {
        return $this === self::File || $this === self::Both;
    }

    public function toStdout(): bool
    {
        return $this === self::Stdout || $this === self::Both;
    }
    case File   = 'file';
    case Stdout = 'stdout';
    case Both   = 'both';
}
