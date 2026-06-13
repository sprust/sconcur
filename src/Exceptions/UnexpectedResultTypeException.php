<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use LogicException;

/**
 * A resumed fiber received a value that is not a task result — a protocol
 * desync between the PHP layer and the extension.
 */
class UnexpectedResultTypeException extends LogicException
{
}
