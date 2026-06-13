<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use RuntimeException;

/**
 * Thrown into a still-suspended fiber when its WaitGroup is stopped (early break,
 * exception, or destruction). Unwinds the paused callback so its finally-blocks and
 * local destructors run (rollback a transaction, release a lock, ...).
 */
class FlowStoppedException extends RuntimeException
{
}
