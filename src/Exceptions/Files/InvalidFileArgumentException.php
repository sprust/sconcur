<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

use LogicException;

/**
 * The call itself is wrong: a negative length, an unknown hash algorithm, a sub-operation
 * the core does not know, a body it could not read.
 *
 * A LogicException, and deliberately not a FilesException: this is a bug in the calling
 * code, not a condition of the filesystem, and it must not be swallowed by a catch
 * written for the input-output failures.
 */
class InvalidFileArgumentException extends LogicException
{
}
