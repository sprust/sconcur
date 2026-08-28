<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use LogicException;

/**
 * A pool was described in a way it cannot be supervised: a negative worker count, or a
 * master with no group at all. Zero workers is allowed and means "use the number of CPU
 * cores".
 */
class InvalidWorkerCountException extends LogicException
{
}
