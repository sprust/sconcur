<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use LogicException;

/**
 * A coroutine was given a deadline that cannot be met by any code at all — zero or
 * negative milliseconds. A usage bug rather than a runtime failure, so it is raised where
 * the group is built instead of surfacing later as a coroutine that never ran.
 */
class InvalidCoroutineTimeoutException extends LogicException
{
}
