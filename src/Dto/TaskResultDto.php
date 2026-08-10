<?php

declare(strict_types=1);

namespace SConcur\Dto;

use SConcur\Features\MethodEnum;

readonly class TaskResultDto
{
    /**
     * @param int $ownerFiberId the coroutine this result belongs to, carried in
     *                          the frame from the push that created the task
     *                          (0 = none: server streams, the sync path)
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
