<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use RuntimeException;

/**
 * A WaitGroup callback threw — either before its first suspend (on add) or while
 * being resumed with a task result (during iterate). Wraps the underlying
 * throwable so it is not exposed in method signatures.
 */
class CallbackExecutionException extends RuntimeException
{
}
