<?php

declare(strict_types=1);

namespace SConcur\Dto;

use SConcur\Features\MethodEnum;

/**
 * The result of one task, as the core hands it back.
 *
 * The core instantiates this class itself, on the PHP thread inside a wait call,
 * by writing the declared properties below in place (the result builder in
 * ext/sconcur.c). The constructor is not called on that path, and the property
 * names are what the builder looks up: renaming or adding one is a change to both
 * sides in the same commit, like a payload key.
 */
readonly class TaskResultDto
{
    /**
     * @param int $executionMs      how long the task ran inside the core
     * @param int $totalExecutionMs how long the wait call that returned this result
     *                              blocked for it; 0 for a result that was already
     *                              ready when the call was made
     * @param int $ownerFiberId     the coroutine this result belongs to, carried over
     *                              from the push that created the task (0 = none:
     *                              server streams, the sync path)
     */
    public function __construct(
        public string $flowKey,
        public MethodEnum $method,
        public string $key,
        public bool $isError,
        public string $payload,
        public bool $hasNext,
        public int $executionMs,
        public int $totalExecutionMs,
        public int $ownerFiberId = 0,
    ) {
    }
}
