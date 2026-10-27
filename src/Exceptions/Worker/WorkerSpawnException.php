<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use RuntimeException;

/**
 * A worker could not be forked: the system refused (exhausted resources) or the master's
 * process runs threads. The cause is the previous exception.
 */
class WorkerSpawnException extends RuntimeException
{
}
