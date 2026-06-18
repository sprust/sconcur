<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use RuntimeException;

/**
 * A required path (runtime directory, log directory or worker script) is missing or
 * not writable/readable, so the master cannot operate.
 */
class RuntimePathException extends RuntimeException
{
}
