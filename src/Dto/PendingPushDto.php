<?php

declare(strict_types=1);

namespace SConcur\Dto;

use SConcur\Transport\PayloadInterface;

/**
 * A task an async feature call hands to its resumer through Fiber::suspend instead
 * of pushing it to the extension from the fiber's own stack: the push is performed by
 * Scheduler::dispatchPendingTask on the resuming side. Keeps crossings off
 * coroutine stacks — a fan-out of N live fibers that each crossed the PHP<->extension
 * boundary degrades quadratically (see .ai/plans/async-fan-out-optimization.ru.md).
 */
readonly class PendingPushDto
{
    /**
     * @param bool $awaitResult false marks a fire-and-forget push: the dispatcher
     *                          resumes the coroutine right after the push instead
     *                          of registering it to wait for the task's result
     *                          (see FeatureExecutor::execNoResult).
     */
    public function __construct(
        public string $flowKey,
        public PayloadInterface $payload,
        public bool $awaitResult = true,
    ) {
    }
}
