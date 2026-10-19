<?php

declare(strict_types=1);

namespace SConcur\Process\Dto;

/**
 * A child that has exited: its exit code, or the number of the signal that ended it.
 * Neither is known for a pid that turned out not to be a child of this process any more
 * — it is reported all the same, so the caller stops waiting for it.
 */
readonly class ProcessExit
{
    public function __construct(
        public int $pid,
        public ?int $exitCode,
        public ?int $signal,
    ) {
    }

    /** Exited on its own with code 0. */
    public function isClean(): bool
    {
        return $this->exitCode === 0;
    }
}
