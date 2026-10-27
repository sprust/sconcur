<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use RuntimeException;

/**
 * A path the master needs (the runtime or log directory, the lock or state file) cannot
 * be created or written, so the master cannot operate.
 */
class RuntimePathException extends RuntimeException
{
}
