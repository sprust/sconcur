<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use RuntimeException;

/**
 * A task call into the extension failed (push, next, wait or fiber resume).
 * Wraps the underlying throwable so it is not exposed in method signatures.
 */
class TaskExecutionException extends RuntimeException
{
}
