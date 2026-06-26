<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use RuntimeException;

/**
 * The loaded "sconcur" extension version does not match the exact version this
 * package is built against (the PHP package and the Go extension are released
 * together): the PHP <-> Go protocol may not match, so usage is refused. Rebuild
 * the extension (make ext-build).
 */
class IncompatibleExtensionVersionException extends RuntimeException
{
}
