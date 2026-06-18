<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use LogicException;

/**
 * The requested worker count is invalid (negative). Zero is allowed and means
 * "use the number of CPU cores".
 */
class InvalidWorkerCountException extends LogicException
{
}
