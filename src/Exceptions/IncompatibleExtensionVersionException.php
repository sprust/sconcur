<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use RuntimeException;

/**
 * The loaded "sconcur" extension is older than the version this package
 * requires: the PHP <-> Go protocol may not match, so usage is refused.
 * Rebuild the extension (make ext-build).
 */
class IncompatibleExtensionVersionException extends RuntimeException
{
}
