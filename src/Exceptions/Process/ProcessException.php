<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Process;

use RuntimeException;

/**
 * The operating system refused a process-control call — a fork, a pipe, a waitpid, a
 * signal — or the call could not be read; the message names the call and the reason.
 */
class ProcessException extends RuntimeException
{
}
