<?php

declare(strict_types=1);

namespace SConcur\Dto;

/**
 * The marker a coroutine suspends with from Scheduler::switch(): a cooperative
 * yield with no task attached. The resumer (Scheduler::dispatchPendingTask) parks
 * the coroutine in the switched queue; the scheduler resumes it once nothing else
 * is deliverable right now. What it is for: docs/coroutine-switching.md.
 */
readonly class PendingSwitchDto
{
}
