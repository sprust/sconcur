<?php

declare(strict_types=1);

namespace SConcur\Exceptions\File;

use LogicException;

/**
 * The fopen-style mode string passed to FileSystem::open() is not one of the
 * supported modes (r, r+, w, w+, a, a+, x, x+, c, c+, with an optional b/t suffix).
 * A usage bug, hence a LogicException.
 */
class InvalidFileModeException extends LogicException
{
}
