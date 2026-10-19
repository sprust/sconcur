<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Process;

use LogicException;

/**
 * A fork was asked of a process that runs threads: the extension's runtime is up, or
 * something else started them. A fork keeps only the calling thread, so a lock any other
 * one held would stay held in the child; the fork has to happen before any asynchronous
 * work.
 */
class ForkRefusedException extends LogicException
{
}
