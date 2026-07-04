<?php

declare(strict_types=1);

namespace SConcur\Dto;

/**
 * The next-batch counterpart of PendingPushDto: an async streaming call
 * (FeatureExecutor::next) hands the re-arm of an existing task to its resumer
 * through Fiber::suspend, so the cgo call happens off the fiber's stack.
 */
readonly class PendingNextDto
{
    public function __construct(
        public string $flowKey,
        public string $taskKey,
    ) {
    }
}
