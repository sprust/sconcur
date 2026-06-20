<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use RuntimeException;

/**
 * proc_open failed to start a worker process (bad interpreter path, unreadable
 * script, exhausted resources). Carries the attempted command in the message.
 */
class WorkerSpawnException extends RuntimeException
{
}
