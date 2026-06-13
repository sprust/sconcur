<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use LogicException;

/**
 * A result arrived for a different task than the one awaited — a protocol
 * desync between the PHP layer and the extension.
 */
class UnexpectedTaskKeyException extends LogicException
{
}
