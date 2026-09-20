<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

use RuntimeException;

/**
 * The base of every failure that happens while the extension works with a file.
 *
 * It extends RuntimeException because a missing file, a refused permission and a full
 * disk are runtime conditions whatever the caller does — the project's rule for the kind
 * (.ai/README.md, "Exceptions").
 *
 * What does not descend from here is InvalidFileArgumentException, a LogicException: a
 * negative length or an unknown algorithm is a bug in the calling code, not a condition
 * of the filesystem. FileStreamClosedException does descend from here despite reading
 * like a usage mistake, because a stream is also released by its coroutine ending — the
 * caller can be holding a handle that was valid when it got it.
 */
class FilesException extends RuntimeException
{
}
