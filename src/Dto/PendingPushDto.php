<?php

declare(strict_types=1);

namespace SConcur\Dto;

use SConcur\Transport\PayloadInterface;

/**
 * A task an async feature call hands to its resumer through Fiber::suspend instead
 * of pushing it to Go from the fiber's own stack: the push is performed by
 * Scheduler::dispatchPendingTask on the resuming side. Keeps cgo calls off
 * coroutine stacks — a fan-out of N live fibers that each crossed the PHP<->Go
 * boundary degrades quadratically (see .ai/plans/async-fan-out-optimization.ru.md).
 */
readonly class PendingPushDto
{
    public function __construct(
        public string $flowKey,
        public PayloadInterface $payload,
    ) {
    }
}
