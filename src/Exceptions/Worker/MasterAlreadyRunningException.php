<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use RuntimeException;

/**
 * Another master already holds the runtime lock for this runtime directory, so a
 * second instance must not start. The lock is an exclusive flock the kernel
 * releases automatically when the holding process dies (no stale-lock problem).
 */
class MasterAlreadyRunningException extends RuntimeException
{
}
