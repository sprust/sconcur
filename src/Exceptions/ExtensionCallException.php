<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use RuntimeException;

/**
 * An extension call failed before the task started: no result will ever
 * arrive for it, so waiting is not allowed to begin.
 */
class ExtensionCallException extends RuntimeException
{
}
