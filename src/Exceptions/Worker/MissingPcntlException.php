<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use RuntimeException;

/**
 * The worker master needs ext-pcntl and ext-posix to forward signals and supervise
 * workers; one of them is not loaded, so a managed graceful shutdown is impossible.
 */
class MissingPcntlException extends RuntimeException
{
}
