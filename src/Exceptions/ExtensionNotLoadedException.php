<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use RuntimeException;

/**
 * The "sconcur" PHP extension is not loaded, so no task can run.
 */
class ExtensionNotLoadedException extends RuntimeException
{
}
