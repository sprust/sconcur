<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * One complete line captured from a worker's stdout/stderr, to be re-emitted into
 * the master log. $isError marks lines that came from stderr (logged at ERROR).
 */
readonly class WorkerOutputLine
{
    public function __construct(
        public bool $isError,
        public string $line,
    ) {
    }
}
