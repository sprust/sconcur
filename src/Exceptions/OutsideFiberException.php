<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use LogicException;

/**
 * An async task tried to wait or suspend outside of a fiber — a usage bug.
 */
class OutsideFiberException extends LogicException
{
}
